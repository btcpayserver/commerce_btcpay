<?php

namespace Drupal\commerce_btcpay\Plugin\Commerce\PaymentGateway;

use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\InvoiceCheckoutOptions;
use BTCPayServer\Client\Webhook;
use BTCPayServer\Result\Invoice as InvoiceResult;
use BTCPayServer\Util\PreciseNumber;
use Drupal\commerce_btcpay\ApiKeyVerifier;
use Drupal\commerce_btcpay\CredentialStorage;
use Drupal\commerce_btcpay\InvoiceStatus;
use Drupal\commerce_btcpay\ServerUrlPolicy;
use Drupal\commerce_btcpay\WebhookEventStorage;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the BTCPay off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "btcpay_redirect",
 *   label = @Translation("BTCPay Server (Off-site redirect)"),
 *   display_label = @Translation("Pay with Bitcoin, Lightning Network"),
 *   modes = {
 *     "live" = @Translation("Live"),
 *   },
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_btcpay\PluginForm\BtcPayRedirectForm",
 *   },
 *   payment_type = "payment_default",
 *   requires_billing_information = FALSE,
 * )
 */
class BtcPayRedirect extends OffsitePaymentGatewayBase implements BtcPayInterface {

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Protected credential storage.
   */
  protected CredentialStorage $credentialStorage;

  /**
   * The BTCPay Server URL policy.
   */
  protected ServerUrlPolicy $serverUrlPolicy;

  /**
   * The API-key verifier.
   */
  protected ApiKeyVerifier $apiKeyVerifier;

  /**
   * The lock backend.
   */
  protected LockBackendInterface $lock;

  /**
   * Webhook delivery processing storage.
   */
  protected WebhookEventStorage $webhookEventStorage;

  /**
   * Gateway ID available while the plugin configuration form is active.
   */
  protected ?string $activeGatewayId = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->logger = $container->get('logger.channel.commerce_btcpay');
    $instance->credentialStorage = $container->get('commerce_btcpay.credential_storage');
    $instance->serverUrlPolicy = $container->get('commerce_btcpay.server_url_policy');
    $instance->apiKeyVerifier = $container->get('commerce_btcpay.api_key_verifier');
    $instance->lock = $container->get('lock');
    $instance->webhookEventStorage = $container->get('commerce_btcpay.webhook_event_storage');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'server_url' => '',
      'store_id' => '',
      'webhook_id' => '',
      'send_buyer_email' => FALSE,
      'debug_mode' => FALSE,
      'collect_billing_information' => FALSE,
      'payment_method_types' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form_object = $form_state->getFormObject();
    assert($form_object instanceof EntityFormInterface);
    $gateway = $form_object->getEntity();
    $this->activeGatewayId = $gateway->id() ? (string) $gateway->id() : NULL;
    $has_stored_key = $this->activeGatewayId !== NULL && $this->hasApiKey();
    $can_authorize = $this->activeGatewayId !== NULL && !$gateway->isNew();

    $form['#attached']['library'][] = 'commerce_btcpay/api_key_redirect';
    $form['#attached']['drupalSettings']['commerce_btcpay'] = [
      'gateway_id' => $can_authorize ? $this->activeGatewayId : '',
      'authorize_url' => $can_authorize
        ? Url::fromRoute('commerce_btcpay.api_key_authorize')->toString()
        : '',
      'allow_insecure_http' => $this->serverUrlPolicy->allowsInsecureHttp(),
    ];

    $form['mode']['#access'] = FALSE;
    if (isset($form['collect_billing_information'])) {
      $form['collect_billing_information']['#access'] = FALSE;
      $form['collect_billing_information']['#value'] = FALSE;
    }
    if (isset($form['payment_method_types'])) {
      $form['payment_method_types']['#access'] = FALSE;
      $form['payment_method_types']['#value'] = [];
    }

    $form['server_url'] = [
      '#type' => 'url',
      '#title' => $this->t('BTCPay Server URL'),
      '#description' => $this->t('Use the HTTPS base URL of your BTCPay Server (for example, https://btcpay.example.com).'),
      '#default_value' => $this->configuration['server_url'],
      '#required' => TRUE,
    ];
    $form['generate_api_key'] = [
      '#type' => 'button',
      '#value' => $this->t('Generate API Key'),
      '#disabled' => !$can_authorize,
      '#attributes' => [
        'class' => ['btcpay-generate-api-key', 'button', 'button--primary'],
      ],
      '#prefix' => '<div class="form-item">',
      '#suffix' => '<div class="description">' . ($can_authorize
        ? $this->t('Authorize the saved gateway with a least-privilege API key.')
        : $this->t('Save this payment gateway before generating an API key.')) . '</div></div>',
    ];
    $form['store_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Store ID'),
      '#description' => $this->t('The store selected during API-key authorization. It may be blank only while saving a new disabled gateway.'),
      '#default_value' => $this->configuration['store_id'],
    ];
    $form['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('New API key'),
      '#maxlength' => 2048,
      '#description' => $has_stored_key
        ? $this->t('An API key is stored securely. Leave blank to keep it unchanged.')
        : $this->t('Enter an API key, or save this gateway as disabled and then use Generate API Key.'),
      '#attributes' => ['autocomplete' => 'new-password'],
    ];
    $form['webhook_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('New webhook secret'),
      '#maxlength' => 512,
      '#description' => $this->t('Leave blank to preserve the encrypted secret or generate one automatically.'),
      '#attributes' => ['autocomplete' => 'new-password'],
    ];
    $form['send_buyer_email'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send the customer email address to BTCPay Server'),
      '#description' => $this->t('Disabled by default to minimize customer data shared with the payment server.'),
      '#default_value' => $this->configuration['send_buyer_email'],
    ];
    $form['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug mode'),
      '#description' => $this->t('Log additional non-sensitive payment state details.'),
      '#default_value' => $this->configuration['debug_mode'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $form_object = $form_state->getFormObject();
    assert($form_object instanceof EntityFormInterface);
    $gateway = $form_object->getEntity();
    $gateway_id = $gateway->id() ?: $form_state->getValue('id');
    $this->activeGatewayId = is_string($gateway_id) && $gateway_id !== '' ? $gateway_id : NULL;
    $values = $form_state->getValue($form['#parents']);

    try {
      $server_url = $this->serverUrlPolicy->normalize((string) ($values['server_url'] ?? ''));
      $form_state->setValueForElement($form['server_url'], $server_url);
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setError($form['server_url'], $this->t('@message', ['@message' => $e->getMessage()]));
      return;
    }

    $api_key = (string) ($values['api_key'] ?? '');
    $webhook_secret = (string) ($values['webhook_secret'] ?? '');
    if ($webhook_secret !== '' && strlen($webhook_secret) < 32) {
      $form_state->setError($form['webhook_secret'], $this->t('The webhook secret must contain at least 32 characters.'));
    }
    if ($api_key === '') {
      $api_key = $this->getApiKey();
    }
    if ($api_key === '') {
      $gateway_enabled = (bool) $form_state->getValue('status');
      if ($gateway_enabled) {
        $form_state->setError($form['api_key'], $this->t('An enabled gateway requires a verified API key. Save it as disabled before using Generate API Key.'));
      }
      if ($webhook_secret !== '') {
        $form_state->setError($form['webhook_secret'], $this->t('A webhook secret cannot be saved without an API key.'));
      }
      return;
    }

    $store_id = trim((string) ($values['store_id'] ?? ''));
    $form_state->setValueForElement($form['store_id'], $store_id);
    if ($store_id === '') {
      $form_state->setError($form['store_id'], $this->t('A store ID is required when an API key is configured.'));
      return;
    }

    try {
      $this->apiKeyVerifier->verify($server_url, $api_key, $store_id);
    }
    catch (\Throwable) {
      $form_state->setError($form['api_key'], $this->t('The API key, required permissions, server URL, and store ID could not be verified.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $webhook_id = is_string($this->configuration['webhook_id'] ?? NULL)
      ? $this->configuration['webhook_id']
      : '';
    parent::submitConfigurationForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }

    $values = $form_state->getValue($form['#parents']);
    $form_object = $form_state->getFormObject();
    assert($form_object instanceof EntityFormInterface);
    $gateway = $form_object->getEntity();
    $gateway_id = $gateway->id() ?: $form_state->getValue('id');
    $this->activeGatewayId = is_string($gateway_id) && $gateway_id !== '' ? $gateway_id : NULL;
    if ($this->activeGatewayId === NULL) {
      throw new \LogicException('A gateway ID is required before storing BTCPay configuration.');
    }

    $this->configuration['server_url'] = $this->serverUrlPolicy->normalize($values['server_url']);
    $this->configuration['store_id'] = (string) $values['store_id'];
    $this->configuration['webhook_id'] = $webhook_id;
    $this->configuration['send_buyer_email'] = !empty($values['send_buyer_email']);
    $this->configuration['debug_mode'] = !empty($values['debug_mode']);
    $this->configuration['collect_billing_information'] = FALSE;
    $this->configuration['payment_method_types'] = [];
    unset($this->configuration['api_key'], $this->configuration['webhook_secret']);

    $credentials = [];
    if (!empty($values['api_key'])) {
      $credentials['api_key'] = (string) $values['api_key'];
    }
    if (!empty($values['webhook_secret'])) {
      $credentials['webhook_secret'] = (string) $values['webhook_secret'];
    }
    if ($credentials !== []) {
      $this->credentialStorage->set($this->activeGatewayId, $credentials);
    }

    if (!$this->hasApiKey()) {
      $this->messenger()->addStatus($this->t('The disabled gateway was saved. Edit it and use Generate API Key to finish authorization.'));
      return;
    }

    if ($this->setupWebhook($this->activeGatewayId)) {
      $this->messenger()->addStatus($this->t('The signed BTCPay webhook was configured successfully.'));
    }
    else {
      $this->messenger()->addWarning($this->t('The webhook could not be configured. Review the log before accepting payments.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setupWebhook(?string $gateway_id = NULL): bool {
    $gateway_id = $gateway_id ?: $this->getGatewayId();
    if (!$gateway_id) {
      $this->logger->error('Cannot configure BTCPay webhook without a gateway ID.');
      return FALSE;
    }
    $this->activeGatewayId = $gateway_id;

    try {
      $server_url = $this->serverUrlPolicy->normalize($this->configuration['server_url']);
      $api_key = $this->getApiKey();
      $store_id = (string) $this->configuration['store_id'];
      if ($api_key === '' || $store_id === '') {
        throw new \UnexpectedValueException('BTCPay credentials or store ID are missing.');
      }

      $client = new Webhook($server_url, $api_key);
      $webhook_url = Url::fromRoute('commerce_btcpay.notify', [
        'commerce_payment_gateway' => $gateway_id,
      ], ['absolute' => TRUE])->toString();
      $webhook_id = $this->configuration['webhook_id'] ?: NULL;

      if ($webhook_id) {
        try {
          $client->getWebhook($store_id, $webhook_id);
        }
        catch (\Throwable) {
          $webhook_id = NULL;
        }
      }
      if (!$webhook_id) {
        foreach ($client->getStoreWebhooks($store_id)->all() as $existing_webhook) {
          if ($existing_webhook->getUrl() === $webhook_url) {
            $webhook_id = $existing_webhook->getId();
            break;
          }
        }
      }

      $secret = $this->getWebhookSecret();
      if (strlen($secret) < 32) {
        $secret = bin2hex(random_bytes(32));
        $this->credentialStorage->set($gateway_id, ['webhook_secret' => $secret]);
      }
      $events = [
        'InvoiceReceivedPayment',
        'InvoicePaymentSettled',
        'InvoiceProcessing',
        'InvoiceExpired',
        'InvoiceSettled',
        'InvoiceInvalid',
      ];

      if ($webhook_id) {
        $client->updateWebhook($store_id, $webhook_url, $webhook_id, $events, TRUE, TRUE, $secret);
      }
      else {
        $result = $client->createWebhook($store_id, $webhook_url, $events, $secret, TRUE, TRUE);
        $data = $result->getData();
        $webhook_id = $data['id'] ?? NULL;
      }
      if (!is_string($webhook_id) || $webhook_id === '') {
        throw new \UnexpectedValueException('BTCPay did not return a webhook ID.');
      }

      $this->configuration['webhook_id'] = $webhook_id;
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not configure the BTCPay webhook (@type).', [
        '@type' => get_class($e),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getInvoiceClient() {
    try {
      $server_url = $this->serverUrlPolicy->normalize($this->configuration['server_url']);
      $api_key = $this->getApiKey();
      if ($api_key === '') {
        throw new \UnexpectedValueException('BTCPay API key is missing.');
      }
      return new Invoice($server_url, $api_key);
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not initialize the BTCPay invoice client (@type).', [
        '@type' => get_class($e),
      ]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createInvoice(PaymentInterface $payment, array $options = []) {
    $client = $this->getInvoiceClient();
    $order = $payment->getOrder();
    $amount = $payment->getAmount();
    if (!$client || !$order || !$amount || !$amount->isPositive()) {
      throw PaymentGatewayException::createForPayment($payment, 'Could not initialize a valid BTCPay invoice.');
    }

    try {
      $checkout_options = new InvoiceCheckoutOptions();
      if (!empty($options['return_url'])) {
        $checkout_options->setRedirectURL($options['return_url']);
      }
      $email = !empty($this->configuration['send_buyer_email']) ? $order->getEmail() : NULL;

      if (!empty($this->configuration['debug_mode'])) {
        $this->logger->debug('Creating BTCPay invoice for order @order, amount @amount @currency.', [
          '@order' => $order->id(),
          '@amount' => $amount->getNumber(),
          '@currency' => $amount->getCurrencyCode(),
        ]);
      }

      return $client->createInvoice(
        $this->configuration['store_id'],
        $amount->getCurrencyCode(),
        PreciseNumber::parseString($amount->getNumber()),
        (string) $order->id(),
        $email,
        NULL,
        $checkout_options,
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not create a BTCPay invoice (@type).', [
        '@type' => get_class($e),
      ]);
      throw PaymentGatewayException::createForPayment($payment, 'Could not create an invoice on BTCPay Server.', 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getInvoice(string $invoiceId) {
    $client = $this->getInvoiceClient();
    if (!$client) {
      return NULL;
    }

    try {
      return $client->getInvoice($this->configuration['store_id'], $invoiceId);
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not retrieve BTCPay invoice @invoice (@type).', [
        '@invoice' => $invoiceId,
        '@type' => get_class($e),
      ]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $order_data = $order->getData('btcpay');
    $invoice_id = is_array($order_data) ? ($order_data['invoice_id'] ?? NULL) : NULL;
    if (!is_string($invoice_id) || $invoice_id === '') {
      throw new PaymentGatewayException('Invoice ID missing for this BTCPay transaction.');
    }

    $gateway_id = $this->getGatewayId();
    if (!$gateway_id) {
      throw new PaymentGatewayException('Payment gateway ID missing for this BTCPay transaction.');
    }
    $lock_name = $this->getInvoiceLockName($gateway_id, $invoice_id);
    if (!$this->lock->acquire($lock_name, 30.0)) {
      throw new PaymentGatewayException('The BTCPay transaction is already being processed. Try again.');
    }

    try {
      $payment = $this->loadPaymentForInvoice($invoice_id);
      if (!$payment) {
        throw new PaymentGatewayException('No unique local payment is bound to this BTCPay invoice.');
      }
      $invoice = $this->getInvoice($invoice_id);
      if (!$invoice) {
        throw PaymentGatewayException::createForPayment($payment, 'Could not retrieve the BTCPay invoice.');
      }

      try {
        $this->assertInvoiceBinding($payment, $invoice, $order);
      }
      catch (\Throwable $e) {
        $this->logger->error('Rejected BTCPay return for invoice @invoice: @error', [
          '@invoice' => $invoice_id,
          '@error' => $e->getMessage(),
        ]);
        throw PaymentGatewayException::createForPayment($payment, 'The BTCPay invoice did not match the local payment.', 0, $e);
      }

      $invoice_data = $invoice->getData();
      $target_state = InvoiceStatus::paymentState($invoice_data);
      $this->applyAuthoritativeState($payment, $invoice_data, $target_state);
      if (!InvoiceStatus::isCheckoutComplete($invoice_data)) {
        throw PaymentGatewayException::createForPayment($payment, 'The BTCPay invoice is not fully settled.');
      }
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    $payload = $request->getContent();
    $signature = $request->headers->get('BTCPay-Sig');
    if (!$this->validWebhookRequest($signature, $payload)) {
      $this->logger->warning('Rejected a BTCPay webhook with an invalid signature.');
      return new Response('', 403);
    }

    try {
      $data = json_decode($payload, TRUE, 32, JSON_THROW_ON_ERROR);
      $event = $this->validateWebhookPayload($data);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Rejected a malformed BTCPay webhook: @error', ['@error' => $e->getMessage()]);
      return new Response('', 400);
    }

    if (!hash_equals((string) $this->configuration['store_id'], $event['store_id'])
      || empty($this->configuration['webhook_id'])
      || !hash_equals((string) $this->configuration['webhook_id'], $event['webhook_id'])) {
      $this->logger->warning('Rejected a BTCPay webhook for an unexpected store or webhook.');
      return new Response('', 403);
    }

    $gateway_id = $this->getGatewayId();
    if (!$gateway_id) {
      return new Response('', 500);
    }
    $lock_name = $this->getInvoiceLockName($gateway_id, $event['invoice_id']);
    if (!$this->lock->acquire($lock_name, 30.0)) {
      return new Response('', 503, ['Retry-After' => '2']);
    }

    try {
      if ($this->webhookEventStorage->isProcessed($gateway_id, $event['delivery_id'], $event['original_delivery_id'])) {
        return new Response('', 200);
      }

      // Always fetch current authoritative state. Event type never determines
      // the resulting payment state.
      $invoice = $this->getInvoice($event['invoice_id']);
      if (!$invoice) {
        return new Response('', 503, ['Retry-After' => '5']);
      }
      $payment = $this->loadPaymentForInvoice($event['invoice_id']);
      if (!$payment) {
        $this->logger->warning('BTCPay webhook invoice @invoice has no unique pre-existing payment.', [
          '@invoice' => $event['invoice_id'],
        ]);
        return new Response('', 409);
      }

      try {
        $this->assertInvoiceBinding($payment, $invoice);
      }
      catch (\Throwable $e) {
        $this->logger->error('Rejected mismatched BTCPay webhook invoice @invoice: @error', [
          '@invoice' => $event['invoice_id'],
          '@error' => $e->getMessage(),
        ]);
        return new Response('', 400);
      }

      $last_timestamp = $this->webhookEventStorage->getLastTimestamp($gateway_id, $event['invoice_id']);
      if ($event['timestamp'] >= $last_timestamp) {
        $invoice_data = $invoice->getData();
        $this->applyAuthoritativeState($payment, $invoice_data, InvoiceStatus::paymentState($invoice_data));
      }
      $this->webhookEventStorage->markProcessed(
        $gateway_id,
        $event['invoice_id'],
        $event['delivery_id'],
        $event['original_delivery_id'],
        $event['timestamp'],
      );
      return new Response('', 200);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to process a verified BTCPay webhook (@type).', [
        '@type' => get_class($e),
      ]);
      return new Response('', 500);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Loads the one existing local payment bound to a remote invoice ID.
   */
  protected function loadPaymentForInvoice(string $invoice_id): ?PaymentInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_payment');
    $query = $storage->getQuery();
    // PHPStan Drupal misclassifies the literal FALSE as always truthy here.
    // @phpstan-ignore-next-line
    $query->accessCheck(FALSE);
    $payment_ids = $query
      ->condition('remote_id', $invoice_id)
      ->execute();
    if (count($payment_ids) !== 1) {
      return NULL;
    }
    $storage->resetCache($payment_ids);
    $payment = $storage->load(reset($payment_ids));
    return $payment instanceof PaymentInterface ? $payment : NULL;
  }

  /**
   * Builds the shared lock name for every invoice state update path.
   */
  protected function getInvoiceLockName(string $gateway_id, string $invoice_id): string {
    return 'commerce_btcpay.invoice.' . hash('sha256', $gateway_id . "\0" . $invoice_id);
  }

  /**
   * Requires an exact invoice/payment/order/gateway/amount/currency binding.
   */
  protected function assertInvoiceBinding(
    PaymentInterface $payment,
    InvoiceResult $invoice,
    ?OrderInterface $expected_order = NULL,
  ): void {
    $invoice_data = $invoice->getData();
    $invoice_id = $invoice_data['id'] ?? NULL;
    if (!is_string($invoice_id) || !hash_equals((string) $payment->getRemoteId(), $invoice_id)) {
      throw new \UnexpectedValueException('Remote invoice ID mismatch.');
    }
    if ($payment->getPaymentGatewayId() !== $this->getGatewayId()) {
      throw new \UnexpectedValueException('Payment gateway mismatch.');
    }

    $order = $payment->getOrder();
    if (!$order || ($expected_order && $order->id() !== $expected_order->id())) {
      throw new \UnexpectedValueException('Payment order mismatch.');
    }
    $metadata_order_id = $invoice_data['metadata']['orderId'] ?? NULL;
    if (!is_scalar($metadata_order_id) || !hash_equals((string) $order->id(), (string) $metadata_order_id)) {
      throw new \UnexpectedValueException('Invoice metadata order mismatch.');
    }
    $invoice_store_id = $invoice_data['storeId'] ?? NULL;
    if (!is_string($invoice_store_id)
      || $invoice_store_id === ''
      || !hash_equals((string) $this->configuration['store_id'], $invoice_store_id)) {
      throw new \UnexpectedValueException('Invoice store mismatch.');
    }

    $payment_amount = $payment->getAmount();
    if (!$payment_amount) {
      throw new \UnexpectedValueException('Payment amount is missing.');
    }
    $invoice_number = $invoice->getAmount()->__toString();
    $invoice_currency = strtoupper($invoice->getCurrency());
    if (!hash_equals($payment_amount->getCurrencyCode(), $invoice_currency)
      || !$this->decimalAmountsEqual($payment_amount->getNumber(), $invoice_number)) {
      throw new \UnexpectedValueException('Invoice amount or currency mismatch.');
    }
  }

  /**
   * Compares decimal amount strings without losing sub-unit precision.
   */
  protected function decimalAmountsEqual(string $first, string $second): bool {
    $first_scale = str_contains($first, '.') ? strlen(substr(strrchr($first, '.'), 1)) : 0;
    $second_scale = str_contains($second, '.') ? strlen(substr(strrchr($second, '.'), 1)) : 0;
    return bccomp($first, $second, max($first_scale, $second_scale)) === 0;
  }

  /**
   * Applies authoritative state without allowing terminal-state regressions.
   */
  protected function applyAuthoritativeState(PaymentInterface $payment, array $invoice_data, ?string $target_state): void {
    $current_state = $payment->getState()->getId();
    if ($target_state !== NULL && InvoiceStatus::canTransition($current_state, $target_state)) {
      $payment->setState($target_state);
    }
    elseif ($target_state !== NULL && $target_state !== $current_state && !empty($this->configuration['debug_mode'])) {
      $this->logger->debug('Ignored non-monotonic BTCPay payment transition from @from to @to.', [
        '@from' => $current_state,
        '@to' => $target_state,
      ]);
    }

    $remote_state = (string) ($invoice_data['status'] ?? 'Unknown');
    $additional_status = (string) ($invoice_data['additionalStatus'] ?? '');
    if ($additional_status !== '' && $additional_status !== 'None') {
      $remote_state .= ':' . $additional_status;
    }
    $payment->setRemoteState($remote_state);
    $payment->save();
  }

  /**
   * Validates and normalizes required webhook envelope data.
   */
  protected function validateWebhookPayload(mixed $data): array {
    if (!is_array($data)) {
      throw new \UnexpectedValueException('Webhook JSON must be an object.');
    }
    $required_strings = [
      'deliveryId' => 'delivery_id',
      'webhookId' => 'webhook_id',
      'storeId' => 'store_id',
      'invoiceId' => 'invoice_id',
      'type' => 'type',
    ];
    $event = [];
    foreach ($required_strings as $input_key => $output_key) {
      if (!is_string($data[$input_key] ?? NULL) || $data[$input_key] === '' || strlen($data[$input_key]) > 255) {
        throw new \UnexpectedValueException(sprintf('Invalid webhook field: %s', $input_key));
      }
      $event[$output_key] = $data[$input_key];
    }
    if (!in_array($event['type'], [
      'InvoiceReceivedPayment',
      'InvoicePaymentSettled',
      'InvoiceProcessing',
      'InvoiceExpired',
      'InvoiceSettled',
      'InvoiceInvalid',
    ], TRUE)) {
      throw new \UnexpectedValueException('Unexpected webhook event type.');
    }

    $original_id = $data['originalDeliveryId'] ?? $data['orignalDeliveryId'] ?? NULL;
    if (!is_string($original_id) || $original_id === '' || strlen($original_id) > 255) {
      if (!empty($data['isRedelivery'])) {
        throw new \UnexpectedValueException('A redelivery requires its original delivery ID.');
      }
      $original_id = $event['delivery_id'];
    }
    $event['original_delivery_id'] = $original_id;

    if (!is_int($data['timestamp'] ?? NULL) || $data['timestamp'] <= 0) {
      throw new \UnexpectedValueException('Invalid webhook timestamp.');
    }
    $event['timestamp'] = $data['timestamp'];
    return $event;
  }

  /**
   * Validates an incoming webhook signature over the unmodified body.
   */
  protected function validWebhookRequest(?string $signature, string $payload): bool {
    try {
      $secret = $this->getWebhookSecret();
      if (!$signature || $secret === '') {
        return FALSE;
      }
      return Webhook::isIncomingWebhookRequestValid($payload, $signature, $secret);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Gets the current gateway ID in runtime or configuration-form contexts.
   */
  protected function getGatewayId(): ?string {
    if ($this->activeGatewayId !== NULL) {
      return $this->activeGatewayId;
    }
    if ($this->parentEntity->id()) {
      return (string) $this->parentEntity->id();
    }
    return NULL;
  }

  /**
   * Gets the API key, migrating a legacy configuration value when necessary.
   */
  protected function getApiKey(): string {
    $gateway_id = $this->getGatewayId();
    if ($gateway_id) {
      $stored = $this->credentialStorage->get($gateway_id)['api_key'] ?? '';
      if (is_string($stored) && $stored !== '') {
        return $stored;
      }
      if (!empty($this->configuration['api_key']) && is_string($this->configuration['api_key'])) {
        $this->credentialStorage->set($gateway_id, ['api_key' => $this->configuration['api_key']]);
        return $this->configuration['api_key'];
      }
    }
    return '';
  }

  /**
   * Determines whether the current gateway has a usable API key.
   */
  protected function hasApiKey(): bool {
    return $this->getApiKey() !== '';
  }

  /**
   * Gets the webhook secret, migrating legacy configuration when necessary.
   */
  protected function getWebhookSecret(): string {
    $gateway_id = $this->getGatewayId();
    if ($gateway_id) {
      $stored = $this->credentialStorage->get($gateway_id)['webhook_secret'] ?? '';
      if (is_string($stored) && $stored !== '') {
        return $stored;
      }
      if (!empty($this->configuration['webhook_secret']) && is_string($this->configuration['webhook_secret'])) {
        $this->credentialStorage->set($gateway_id, ['webhook_secret' => $this->configuration['webhook_secret']]);
        return $this->configuration['webhook_secret'];
      }
    }
    return '';
  }

}
