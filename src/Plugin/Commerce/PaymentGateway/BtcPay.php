<?php

namespace Drupal\commerce_btcpay\Plugin\Commerce\PaymentGateway;

use Bitpay\Buyer;
use Bitpay\Item;
use Bitpay\Currency;
use Bitpay\KeyManager;
use Drupal\commerce_order\Entity\Order;
use Bitpay\Bitpay;
use Bitpay\Client\Adapter\CurlAdapter;
use Bitpay\Invoice;
use Bitpay\PrivateKey;
use Bitpay\PublicKey;
use Bitpay\SinKey;
use Bitpay\Client\Client;
use Bitpay\Network\Customnet;
use Bitpay\Storage\EncryptedFilesystemStorage;
use Bitpay\Token;
use Drupal\commerce_checkout\CheckoutOrderManagerInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the BTCPay off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "btcpay_redirect",
 *   label = @Translation("BTCPay cryptocurrency (off-site redirect)"),
 *   display_label = @Translation("Cryptocurrency (BTC, LTC, Lightning Network)"),
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_btcpay\PluginForm\BtcPayRedirectForm",
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class BtcPay extends OffsitePaymentGatewayBase {

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The checkout order manager.
   *
   * @var \Drupal\commerce_checkout\CheckoutOrderManagerInterface
   */
  protected $checkoutOrderManager;

  /**
   * The state manager.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, LoggerInterface $logger, CheckoutOrderManagerInterface $checkout_order_manager, StateInterface $state) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->logger = $logger;
    $this->checkoutOrderManager = $checkout_order_manager;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('commerce_btcpay.logger'),
      $container->get('commerce_checkout.checkout_order_manager'),
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'mode' => 'test',
      'pairing_code_livenet' => '',
      'server_livenet' => '',
      'token_livenet' => '',
      'pairing_code_testnet' => '',
      'server_testnet' => '',
      'token_testnet' => '',
      'confirmation_speed' => 'medium',
      'debug_log' => NULL,
      'privacy_email' => NULL,
      'privacy_address' => '1',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Show an error if no private filesystem is configured.
    if (!\Drupal::hasService('stream_wrapper.private')) {
      $this->messenger()->addError(t('Error: you have no private filesystem set up. Please do so before you continue! See docs on <a href="@link" target="_blank">how to configure private files</a> and rebuild cache afterwards.',
        ['@link' => 'https://www.drupal.org/docs/8/core/modules/file/overview#content-accessing-private-files']
      ));
    }

    $form = parent::buildConfigurationForm($form, $form_state);

    $form['server_livenet'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live server host'),
      '#description' => $this->t('Enter a custom live server (without leading https://) here, e.g. <strong>btcpay.domain.tld</strong>. Make sure the server is working with https:// and has a valid SSL certificate.  You can define a custom port using a colon e.g. <strong>btcpay.domain.tld:8080</strong>.'),
      '#default_value' => $this->configuration['server_livenet'],
      '#states' => [
        'visible' => [
          ':input[name="configuration[btcpay_redirect][mode]"]' => ['value' => 'live'],
        ],
      ],
    ];

    $form['pairing_code_livenet'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live server pairing code'),
      '#description' => $this->t('Visit your Manage API Tokens page (on your <strong>btcpay.domain.tld</strong>), click the "Add New Token" button, leave the "Require Authentication" checkbox checked, and enter the pairing code here.'),
      '#default_value' => $this->configuration['pairing_code_livenet'],
      '#states' => [
        'visible' => [
          ':input[name="configuration[btcpay_redirect][mode]"]' => ['value' => 'live'],
        ],
      ],
    ];

    $form['token_livenet'] = [
      '#type' => 'item',
      '#title' => $this->t('Live API token status'),
      '#description' => $this->configuration['token_livenet'] ? $this->t('Configured') : $this->t('Not configured'),
      '#states' => [
        'visible' => [
          ':input[name="configuration[btcpay_redirect][mode]"]' => ['value' => 'live'],
        ],
      ],
    ];

    $form['server_testnet'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test server host'),
      '#description' => $this->t('Enter a custom test server (without leading https://) here, e.g. <strong>btcpay.domain.tld</strong>. Make sure the server is working with https:// and has a valid SSL certificate. You can define a custom port using a colon e.g. <strong>btcpay.domain.tld:8080</strong>.'),
      '#default_value' => $this->configuration['server_testnet'],
      '#states' => [
        'visible' => [
          ':input[name="configuration[btcpay_redirect][mode]"]' => ['value' => 'test'],
        ],
      ],
    ];

    $form['pairing_code_testnet'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test pairing code'),
      '#description' => $this->t('Visit your Manage API Tokens page (on your <strong>btcpay.domain.tld</strong>), click the "Add New Token" button, leave the "Require Authentication" checkbox checked, and enter the pairing code here.'),
      '#default_value' => $this->configuration['pairing_code_testnet'],
      '#states' => [
        'visible' => [
          ':input[name="configuration[btcpay_redirect][mode]"]' => ['value' => 'test'],
        ],
      ],
    ];

    $form['token_testnet'] = [
      '#type' => 'item',
      '#title' => $this->t('Test API token status'),
      '#description' => $this->configuration['token_testnet'] ? $this->t('Configured') : $this->t('Not configured'),
      '#states' => [
        'visible' => [
          ':input[name="configuration[btcpay_redirect][mode]"]' => ['value' => 'test'],
        ],
      ],
    ];

    $form['confirmation_speed'] = [
      '#type' => 'select',
      '#title' => $this->t('Confirmation speed'),
      '#description' => $this->t('Choose after how many confirmations you accept a payment as fully paid ("high": 0-confirmations (only for small sums, danger of double spends), "medium" (default): at least 1 confirmation (~10 minutes), "low" at least 6 confirmations (~1 hour). Note: Lightning Network payments are always assumed to have >6 confirmations as they settle immediately.'),
      '#default_value' => $this->configuration['confirmation_speed'],
      '#options' => [
        'high' => $this->t('High'),
        'medium' => $this->t('Medium'),
        'low' => $this->t('Low'),
      ],
    ];

    $form['privacy'] = [
      '#type' => 'fieldset',
      '#title' => t('Privacy settings'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    ];

    $form['privacy']['privacy_address'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Do NOT transfer customer billing address'),
      '#description' => $this->t('Check this if you do NOT want to transfer customer billing data to BTCPay Server.'),
      '#return_value' => '1',
      '#default_value' => $this->configuration['privacy_address'],
    ];

    $form['privacy']['privacy_email'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Do NOT transfer customer e-mail'),
      '#description' => $this->t('Check this if you do NOT want to transfer customer e-mail to BTCPay Server. Customer will be asked for e-mail on BTCPay payment page.'),
      '#return_value' => '1',
      '#default_value' => $this->configuration['privacy_email'],
    ];

    $form['debug_log'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable verbose logging for debugging.'),
      '#return_value' => '1',
      '#default_value' => $this->configuration['debug_log'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    if (!$form_state->getErrors() && $form_state->isSubmitted()) {
      // TODO: check values hostname, pairing code etc.
      // TODO: check if private filesystem is configured in drupal.
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['server_livenet'] = $values['server_livenet'];
      $this->configuration['pairing_code_livenet'] = $values['pairing_code_livenet'];
      $this->configuration['server_testnet'] = $values['server_testnet'];
      $this->configuration['pairing_code_testnet'] = $values['pairing_code_testnet'];
      $this->configuration['confirmation_speed'] = $values['confirmation_speed'];
      $this->configuration['debug_log'] = $values['debug_log'];
      $this->configuration['privacy_email'] = $values['privacy']['privacy_email'];
      $this->configuration['privacy_address'] = $values['privacy']['privacy_address'];
      $this->configuration['mode'] = $values['mode'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['server_livenet'] = $values['server_livenet'];
      $this->configuration['pairing_code_livenet'] = '';
      $this->configuration['server_testnet'] = $values['server_testnet'];
      $this->configuration['pairing_code_testnet'] = '';
      $this->configuration['confirmation_speed'] = $values['confirmation_speed'];
      $this->configuration['debug_log'] = $values['debug_log'];
      $this->configuration['privacy_email'] = $values['privacy']['privacy_email'];
      $this->configuration['privacy_address'] = $values['privacy']['privacy_address'];

      // Create new keys and tokens on BTCPay Server if we have a pairing code.
      $networks = ['livenet' => 'pairing_code_livenet', 'testnet' => 'pairing_code_testnet'];
      foreach ($networks as $network => $setting) {
        if (!empty($values["$setting"])) {
          $this->createToken($network, $values["$setting"]);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    // Get BTCPay payment data from order.
    $order_btcpay_data = $order->getData('btcpay');
    if (empty($order_btcpay_data['invoice_id'])) {
      throw new PaymentGatewayException('Invoice id missing for this BTCPay transaction.');
    }

    // As original BitPay API has no tokens to verify the counterparty server,
    // we need to query the invoice state to ensure it is payed.
    if (!$invoice = $this->getInvoice($order_btcpay_data['invoice_id'])) {
      // TODO: silently fail, display message and redirect back to cart.
      throw new PaymentGatewayException('Invoice not found.');
    }

    // If the user is anonymous and they provided the email during payment, add
    // it to the order.
    if (empty($order->mail)) {
      $order->setEmail($request->query->get('buyerEmail'));
    }
    $order->save();

    $this->processPayment($invoice);

    if ($this->checkInvoicePaymentFailed($invoice) === TRUE) {
      // If the payment failed (voided/expired) for some reason we need to
      // handle that one here. As BitPay/BTCPay API does not support a cancel
      // url.
      $this->redirectOnPaymentError($order);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    if ($this->debugEnabled()) {
      $this->logger->debug(print_r($request->getContent(), TRUE));
    }

    if (!$responseData = json_decode($request->getContent(), TRUE)) {
      throw new PaymentGatewayException('Response data missing, aborting.');
    }

    if (empty($responseData['id'])) {
      throw new PaymentGatewayException('Invoice id missing for this BTCPay transaction, aborting.');
    }

    // As original BitPay API has no tokens to verify the counterparty server,
    // we need to query the invoice state to ensure it is payed.
    /** @var \Bitpay\Invoice $invoice */
    $invoice = $this->getInvoice($responseData['id']);
    if (empty($invoice)) {
      throw new PaymentGatewayException('Invoice not found on BTCPay server.');
    }

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    if (!$order = Order::load($invoice->getOrderId())) {
      throw new PaymentGatewayException('Could not find matching order.');
    }

    // Set the order to next state after draft (so that the order is placed) if
    // there is an payment completed.
    if ($payment = $this->processPayment($invoice) && $this->checkInvoicePaidFull($invoice)) {
      /** @var \Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface $state_item */
      $state_item = $order->get('state')->first();
      $current_state = $state_item->getValue();

      // We only want to transition order from "draft" to next state.
      if ($current_state['value'] !== 'draft') {
        $this->logger->info(t('onNotify callback: skipping order state transition, order already not in "draft" state anymore, current state: @current-state', ['@current-state' => $current_state['value']]));
        return FALSE;
      }

      // Load transitions and apply the next one (place the order).
      if ($transitions = $state_item->getTransitions()) {
        $state_item->applyTransition(current($transitions));
        // Unlock the order if needed.
        $order->isLocked() ? $order->unlock() : NULL;
        $order->save();
        $this->logger->info(t('onNotify callback: set transition successfully.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function processPayment(Invoice $invoice) {
    // Load the order.
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    if (!$order = Order::load($invoice->getOrderId())) {
      throw new PaymentGatewayException('processPayment: Could not find matching order.');
    }

    $paymentState = $this->mapRemotePaymentState($invoice->getStatus());

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    // Check if the IPN callback (onNotify) already created a payment entry.
    if (!empty($payment = $this->loadExistingPayment($order, $invoice))) {
      $payment->setState($paymentState);
      $payment->setRemoteState($invoice->getStatus());
      $payment->setAmount($this->calculateAmountPaid($invoice));
      $payment->save();

    }
    else {
      // As no payment for that order ID exists create a new one.
      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $payment = $payment_storage->create([
        'state' => $paymentState,
        'amount' => $this->calculateAmountPaid($invoice),
        'payment_gateway' => $this->entityId,
        'order_id' => $order->id(),
        'remote_id' => $invoice->getId(),
        'remote_state' => $invoice->getStatus(),
      ]);
      $payment->save();
    }

    return (!empty($payment)) ? $payment : NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function mapRemotePaymentState($remoteState) {
    // TODO: currently does not handle refunded payments.
    // TODO: custom payment workflows suited for BTCPay.
    $mappedState = '';
    switch ($remoteState) {
      case "paid":
        $mappedState = "authorization";
        break;

      case "confirmed":
      case "complete":
        $mappedState = "completed";
        break;

      case "expired":
        $mappedState = "authorization_expired";
        break;

      case "invalid":
        $mappedState = "authorization_voided";
        break;
    }

    return $mappedState;
  }

  /**
   * {@inheritdoc}
   */
  protected function getServerConfig() {
    if ($this->getMode() === 'live') {
      return $this->prepareServerUrl($this->configuration['server_livenet']);
    }
    else {
      return $this->prepareServerUrl($this->configuration['server_testnet']);
    }
  }

  /**
   * Prepares the server URL.
   */
  private function prepareServerUrl($url) {
    $host = explode(':', $url);
    if (!isset($host[1])) {
      $host[1] = '443';
    }

    return $host;
  }

  /**
   * {@inheritdoc}
   */
  public function createInvoice(OrderInterface $order = NULL, $options = []) {
    $invoice = new Invoice();
    $currency = new Currency();
    $currency->setCode($order->getTotalPrice()->getCurrencyCode());
    $invoice->setCurrency($currency);
    $invoice->setPrice($order->getTotalPrice()->getNumber());
    $invoice->setPaymentTotals($order->getTotalPrice()->getNumber());
    $invoice->setOrderId($order->id());
    $invoice->setPosData($order->id());
    $invoice->setTransactionSpeed($this->configuration['confirmation_speed']);

    // As bitpay API currently supports only one item we set it to the store
    // name.
    $item = new Item();
    $entity_manager = \Drupal::entityTypeManager();
    $store = $entity_manager->getStorage('commerce_store')->load($order->getStoreId());
    $item->setDescription($store->getName());
    $item->setPrice($order->getTotalPrice()->getNumber());
    $invoice->setItem($item);

    // Only add customer data if allowed by privacy settings.
    if ($this->configuration['privacy_email'] !== '1' || $this->configuration['privacy_address'] !== '1') {
      // Prepare BTCPay buyer object.
      $buyer = new Buyer();

      // Only set customer data if billing profile is enaled and also honor
      // address privacy setting.
      if ($order->getBillingProfile() && $this->configuration['privacy_address'] !== '1') {
        /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $billing_address */
        $billing_address = $order->getBillingProfile()->get('address')->first();
        $buyer->setFirstName($billing_address->getGivenName())
          ->setLastName($billing_address->getFamilyName())
          ->setAddress([
            $billing_address->getAddressLine1(),
            $billing_address->getAddressLine2(),
          ])
          ->setCity($billing_address->getLocality())
          ->setState($billing_address->getAdministrativeArea())
          ->setZip($billing_address->getPostalCode())
          ->setCountry($billing_address->getCountryCode());
      }

      // Only set customer email if not disabled.
      if ($this->configuration['privacy_email'] !== '1') {
        $buyer->setEmail($order->getEmail());
      }

      $invoice->setBuyer($buyer);
    }

    // Set return url (where external payment provider should redirect to).
    $invoice->setRedirectUrl($options['return_url']);
    // Set notification url.
    $invoice->setNotificationUrl($this->getNotifyUrl()->toString());

    try {
      $client = $this->getBtcPayClient();
      return $client->createInvoice($invoice);
    }
    catch (\Exception $e) {
      $this->logger->error(t('Error on creating invoice on remote server: @error', ['@error' => $e->getMessage()]));
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getInvoice($invoiceId) {
    try {
      $client = $this->getBtcPayClient();
      $invoice = $client->getInvoice($invoiceId);

      if (empty($invoice->getId())) {
        $this->logger->error(t('Error getting invoice data from remote server, likely authorization problem, or non existing invoice id.'));
        return NULL;
      }
      return $invoice;
    } catch (\Exception $e) {
      $this->logger->error(t('Error getting invoice from remote server: @error', ['@error' => $e->getMessage()]));
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkInvoicePaidFull($invoice) {
    $confirmedStates = ['paid', 'confirmed', 'complete'];

    return in_array($invoice->getStatus(), $confirmedStates);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkInvoicePaymentFailed($invoice) {
    $errorStates = ['expired', 'invalid'];

    return in_array($invoice->getStatus(), $errorStates);
  }

  /**
   * {@inheritdoc}
   */
  protected function loadExistingPayment($order, $invoice) {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payments = $payment_storage->loadByProperties(['order_id' => $order->id(), 'remote_id' => $invoice->getId()]);
    return array_pop($payments);
  }

  /**
   * {@inheritdoc}
   */
  protected function redirectOnPaymentError($order) {
    $this->messenger()->addError(t('The payment process could be completed due to expired or canceled invoice. Please try again or change payment option in previous step by clicking on the [back] button.'));

    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
    $checkout_flow = $order->get('checkout_flow')->entity;
    $checkout_flow_plugin = $checkout_flow->getPlugin();
    $step_id = $this->checkoutOrderManager->getCheckoutStepId($order);
    $previous_step_id = $checkout_flow_plugin->getPreviousStepId($step_id);
    $checkout_flow_plugin->redirectToStep($previous_step_id);
  }

  /**
   * {@inheritdoc}
   */
  protected function createToken($network, $pairing_code) {
    // TODO: refactor, not sure what the point is to instantiate Bitpay class,
    // seems only used for config variables set/get/only keymanager but not
    // private keys. Or other way around use it also for api invoice requests.
    global $base_url;
    $password = Crypt::randomBytesBase64();
    $bitpay = new Bitpay([
      'bitpay' => [
        'key_storage_password' => $password,
        'network' => $network,
        // We can only use the private files root path here as BitPay library
        // uses `file_put_contents()` which can't create subfolders.
        'private_key' => "private://btcpay_$network.key",
        'public_key' => "private://btcpay_$network.pub",
      ],
    ]);
    try {
      // Generate and store private key.
      $storage = new EncryptedFilesystemStorage($password);
      $keyManager = new KeyManager($storage);
      $privateKey = new PrivateKey($bitpay->getContainer()->getParameter('bitpay.private_key'));
      $privateKey->generate();
      $keyManager->persist($privateKey);
      // Generate and store public key.
      $publicKey = new PublicKey($bitpay->getContainer()->getParameter('bitpay.public_key'));
      $publicKey->setPrivateKey($privateKey);
      $publicKey->generate();
      $keyManager->persist($publicKey);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to create key pair: %message', ['%message' => $e->getMessage()]));
      return;
    }
    // Create API access token.
    $sin = new SinKey();
    $sin->setPublicKey($publicKey);
    $sin->generate();
    $client = new Client();
    // Use our custom network (btcpay) server.
    $host = $this->getServerConfig();
    $remoteNetwork = new Customnet($host[0], $host[1]);
    $client->setNetwork($remoteNetwork);
    try {
      $token = $client->createToken([
        'id' => (string) $sin,
        'pairingCode' => $pairing_code,
        'label' => $base_url,
      ]);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to create @network token: %message', [
        '%message' => $e->getMessage(),
        '@network' => $network,
      ]));
      return;
    }

    // Set the non user visible data using drupal state api, as non visible
    // config gets.
    // wiped in parent::submitConfigurationForm.
    $this->state->set("commerce_btcpay.token_$network", (string) $token);
    $this->state->set("commerce_btcpay.private_key_password_$network", $password);
    $this->messenger()->addStatus($this->t('New @network API token generated successfully. Encrypted keypair saved to the private filesystem.', ['@network' => $network]));
  }

  /**
   * {@inheritdoc}
   */
  protected function getBtcPayClient() {
    // TODO: refactor to use Bitpay class? with common config abstraction
    // (getBtcPayService?())
    $network = $this->getMode() . 'net';

    try {
      $client = new Client();

      $host = $this->getServerConfig();
      $remoteNetwork = new Customnet($host[0], $host[1]);
      $client->setNetwork($remoteNetwork);

      $adapter = new CurlAdapter();
      $client->setAdapter($adapter);

      $token = new Token();
      $token->setToken($this->state->get("commerce_btcpay.token_$network"));
      // Todo: further investigate: without setting this the php client library
      // does not call the invoice endpoint with correct token parameter on
      // calling getInvoice().
      $token->setFacade('merchant');
      $client->setToken($token);

      $storageEngine = new EncryptedFilesystemStorage($this->state->get("commerce_btcpay.private_key_password_$network"));
      $privateKey = $storageEngine->load("private://btcpay_$network.key");
      $publicKey = $storageEngine->load("private://btcpay_$network.pub");
      $client->setPrivateKey($privateKey);
      $client->setPublicKey($publicKey);

      return $client;
    }
    catch (\Exception $e) {
      $this->logger->error(t('Error getting BitPay Client: @error', ['@error' => $e->getMessage()]));
      return NULL;
    }

  }

  /**
   * Check if verbose logging enabled.
   *
   * @return bool
   *   Whether debugging is enabled or not.
   */
  protected function debugEnabled() {
    return $this->configuration['debug_log'] == 1 ? TRUE : FALSE;
  }

  /**
   * Calculate the total fiat amount paid.
   *
   * @param \Bitpay\Invoice $invoice
   *   The Bitpay invoice to calculate the paid amount for.
   *
   * @return \Drupal\commerce_price\Price
   *   The price object for the amount paid.
   */
  protected function calculateAmountPaid(Invoice $invoice) {
    // Todo: for now we only update the amount when the payment is complete,
    // extend that to partial payments across multiple cryptocurrencies
    // https://github.com/btcpayserver/commerce_btcpay/issues/7
    $allowed_states = ['confirmed', 'complete'];
    if (in_array($invoice->getStatus(), $allowed_states)) {
      return new Price((string) $invoice->getPrice(), $invoice->getCurrency()->getCode());
    }
    else {
      return new Price('0.00', $invoice->getCurrency()->getCode());
    }
  }

}
