<?php

namespace Drupal\commerce_btcpay\Plugin\Commerce\PaymentGateway;

use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\InvoiceCheckoutOptions;
use BTCPayServer\Client\Webhook;
use BTCPayServer\Result\Invoice as InvoiceResult;
use BTCPayServer\Util\PreciseNumber;
use Drupal\commerce_checkout\CheckoutOrderManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides the BTCPay off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "btcpay_redirect",
 *   label = @Translation("BTCPay Server (Off-site redirect)"),
 *   display_label = @Translation("Pay with Bitcoin, Lightning Network"),
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_btcpay\PluginForm\BtcPayRedirectForm",
 *   },
 *   payment_type = "payment_default",
 *   requires_billing_information = FALSE,
 * )
 */
class BtcPayRedirect extends OffsitePaymentGatewayBase implements BtcPayInterface {
  use StringTranslationTrait;

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
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, CheckoutOrderManagerInterface $checkout_order_manager, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
    $this->checkoutOrderManager = $checkout_order_manager;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
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
      $container->get('commerce_checkout.checkout_order_manager'),
      $container->get('logger.factory')->get('commerce_btcpay')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'server_url' => '',
      'api_key' => '',
      'store_id' => '',
      'webhook_secret' => '',
      // Offsite gateways don't collect billing information or payment methods.
      'collect_billing_information' => FALSE,
      'payment_method_types' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Attach the API key redirect JavaScript library.
    $form['#attached']['library'][] = 'commerce_btcpay/api_key_redirect';
    
    // Get the payment gateway entity ID from the form state
    $gateway = $form_state->getFormObject()->getEntity();
    $gateway_id = $gateway->id();
    
    // Pass the gateway entity ID to JavaScript
    $form['#attached']['drupalSettings']['commerce_btcpay']['gateway_id'] = $gateway_id;

    // Hide fields not applicable to offsite payment gateways.
    $form['mode']['#access'] = FALSE;
    // For offsite gateways, we don't collect billing information or payment methods.
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
      '#description' => $this->t('Enter your BTCPay Server URL (e.g., https://btcpay.example.com). Note: .local domains only work on your local network.'),
      '#default_value' => $this->configuration['server_url'] ?? '',
      '#required' => TRUE,
    ];

    $form['generate_api_key'] = [
      '#type' => 'button',
      '#value' => $this->t('Generate API Key'),
      '#attributes' => [
        'class' => ['btcpay-generate-api-key', 'button', 'button--primary'],
      ],
      '#prefix' => '<div class="form-item">',
      '#suffix' => '<div class="description">' . $this->t('Click this button to automatically generate an API key with the correct permissions. You will be redirected to your BTCPay Server to authorize the connection.') . '</div></div>',
    ];

    $form['store_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Store ID'),
      '#description' => $this->t('Your BTCPay Server Store ID. This will be automatically filled when you generate an API key.'),
      '#default_value' => $this->configuration['store_id'] ?? '',
      '#required' => TRUE,
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('Your BTCPay Server API Key. This will be automatically filled when you generate an API key, or you can manually enter one from BTCPay Server under Account > Manage Account > API Keys. Required permissions: View invoices, Create invoice, Modify invoices, Modify stores webhooks.'),
      '#default_value' => $this->configuration['api_key'] ?? '',
      '#required' => TRUE,
    ];

    $form['webhook_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook Secret'),
      '#description' => $this->t('Optional: A secret string to validate webhook requests. Will be auto-configured if left empty.'),
      '#default_value' => $this->configuration['webhook_secret'] ?? '',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['server_url'] = $values['server_url'];
      $this->configuration['api_key'] = $values['api_key'];
      $this->configuration['store_id'] = $values['store_id'];
      $this->configuration['webhook_secret'] = $values['webhook_secret'];
      
      // Ensure offsite gateway settings are correct.
      $this->configuration['collect_billing_information'] = FALSE;
      $this->configuration['payment_method_types'] = [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getInvoiceClient() {
    if (empty($this->configuration['server_url']) || empty($this->configuration['api_key'])) {
      $this->logger->error('BTCPay Server URL or API Key not configured.');
      return NULL;
    }

    try {
      return new Invoice(
        $this->configuration['server_url'],
        $this->configuration['api_key']
      );
    }
    catch (\Exception $e) {
      $this->logger->error('Error creating BTCPay API client: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createInvoice(OrderInterface $order, array $options = []) {
    $client = $this->getInvoiceClient();
    if (!$client) {
      throw new PaymentGatewayException('Could not initialize BTCPay API client.');
    }

    $store_id = $this->configuration['store_id'];
    $amount = $order->getTotalPrice();

    try {
      // Prepare metadata (don't include orderId or buyerEmail as they're passed separately).
      $metadata = [
        'orderNumber' => $order->getOrderNumber(),
      ];

      // Add checkout options.
      $checkoutOptions = new InvoiceCheckoutOptions();
      
      if (!empty($options['return_url'])) {
        $checkoutOptions->setRedirectURL($options['return_url']);
      }

      // Log the invoice creation attempt.
      $this->logger->info('Creating BTCPay invoice - Store: @store, Amount: @amount @currency, Order: @order, Email: @email', [
        '@store' => $store_id,
        '@amount' => $amount->getNumber(),
        '@currency' => $amount->getCurrencyCode(),
        '@order' => $order->id(),
        '@email' => $order->getEmail() ?: 'none',
      ]);

      // Create the invoice.
      // Note: orderId and buyerEmail are passed as parameters, not in metadata.
      $invoice = $client->createInvoice(
        $store_id,
        $amount->getCurrencyCode(),
        PreciseNumber::parseString($amount->getNumber()),
        $order->id(),
        $order->getEmail(),
        $metadata,
        $checkoutOptions
      );

      $this->logger->info('BTCPay invoice created successfully: @invoice_id', [
        '@invoice_id' => $invoice->getData()['id'] ?? 'unknown',
      ]);

      return $invoice;
    }
    catch (\Exception $e) {
      $this->logger->error('Error creating BTCPay invoice: @error | Type: @type | Trace: @trace', [
        '@error' => $e->getMessage(),
        '@type' => get_class($e),
        '@trace' => $e->getTraceAsString(),
      ]);
      throw new PaymentGatewayException('Could not create invoice on BTCPay Server: ' . $e->getMessage());
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
      $store_id = $this->configuration['store_id'];
      return $client->getInvoice($store_id, $invoiceId);
    }
    catch (\Exception $e) {
      $this->logger->error('Error getting BTCPay invoice: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    // Get invoice ID from order data.
    $order_data = $order->getData('btcpay');
    if (empty($order_data['invoice_id'])) {
      throw new PaymentGatewayException('Invoice ID missing for this BTCPay transaction.');
    }

    $invoice_id = $order_data['invoice_id'];
    
    // Fetch the CURRENT invoice status from BTCPay Server.
    // This is critical - we don't trust the return URL, we verify the actual status.
    $this->logger->info('Verifying invoice status from BTCPay Server for invoice: @invoice_id', [
      '@invoice_id' => $invoice_id,
    ]);
    
    $invoice = $this->getInvoice($invoice_id);
    if (!$invoice) {
      throw new PaymentGatewayException('Could not retrieve invoice from BTCPay Server.');
    }

    $invoice_data = $invoice->getData();
    $this->logger->info('Invoice @invoice_id status verified: @status', [
      '@invoice_id' => $invoice_id,
      '@status' => $invoice_data['status'],
    ]);

    // Process the payment based on the VERIFIED invoice status.
    $this->processInvoice($order, $invoice);

    // Check if payment failed and redirect to previous step.
    if (in_array($invoice_data['status'], ['Expired', 'Invalid'])) {
      $this->logger->warning('Payment failed for order @order_id, invoice status: @status', [
        '@order_id' => $order->id(),
        '@status' => $invoice_data['status'],
      ]);
      $this->redirectOnPaymentError($order);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    $this->messenger()->addMessage(
      $this->t('Payment was cancelled. You may resume the checkout process when ready.')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    // Get the webhook payload.
    $payload = $request->getContent();
    $data = json_decode($payload, TRUE);

    if (empty($data['invoiceId'])) {
      $this->logger->error('BTCPay webhook: Invoice ID missing.');
      return;
    }

    // Verify webhook signature if secret is configured.
    if (!empty($this->configuration['webhook_secret'])) {
      $signature = $request->headers->get('BTCPay-Sig');
      if (!$this->verifyWebhookSignature($payload, $signature)) {
        $this->logger->error('BTCPay webhook: Invalid signature.');
        throw new PaymentGatewayException('Invalid webhook signature.');
      }
    }

    // Get the invoice.
    $invoice = $this->getInvoice($data['invoiceId']);
    if (!$invoice) {
      $this->logger->error('BTCPay webhook: Could not retrieve invoice.');
      return;
    }

    // Get order ID from invoice metadata.
    $metadata = $invoice->getData();
    if (empty($metadata['orderId'])) {
      $this->logger->error('BTCPay webhook: Order ID missing from invoice metadata.');
      return;
    }

    // Load the order.
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($metadata['orderId']);
    if (!$order) {
      $this->logger->error('BTCPay webhook: Order not found.');
      return;
    }

    // Process the invoice.
    $this->processInvoice($order, $invoice);
  }

  /**
   * Process an invoice and create/update payment.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \BTCPayServer\Result\Invoice $invoice
   *   The BTCPay invoice.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|null
   *   The payment entity or NULL.
   */
  protected function processInvoice(OrderInterface $order, InvoiceResult $invoice) {
    $payment_storage = \Drupal::entityTypeManager()->getStorage('commerce_payment');
    $invoice_id = $invoice->getData()['id'];
    $status = $invoice->getData()['status'];

    // Debug logging.
    \Drupal::logger('commerce_btcpay')->debug('processInvoice called for order @order_id, invoice @invoice_id, status @status', [
      '@order_id' => $order->id(),
      '@invoice_id' => $invoice_id,
      '@status' => $status,
    ]);

    // Check if payment already exists.
    $payments = $payment_storage->loadByProperties([
      'order_id' => $order->id(),
      'remote_id' => $invoice_id,
    ]);
    $payment = reset($payments);

    // Map BTCPay status to Commerce payment state.
    $payment_state = $this->mapInvoiceStatus($status);

    if ($payment) {
      // Update existing payment.
      $payment->setState($payment_state);
      $payment->setRemoteState($status);
      $payment->save();
      \Drupal::logger('commerce_btcpay')->debug('Updated existing payment @payment_id', ['@payment_id' => $payment->id()]);
    }
    else {
      // Create new payment.
      // Get the payment gateway entity ID from the parent entity.
      $payment_gateway_id = $this->parentEntity ? $this->parentEntity->id() : NULL;
      
      \Drupal::logger('commerce_btcpay')->debug('Creating new payment with gateway @gateway_id', ['@gateway_id' => $this->safeLogValue($payment_gateway_id)]);
      
      $payment = $payment_storage->create([
        'state' => $payment_state,
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $payment_gateway_id,
        'order_id' => $order->id(),
        'remote_id' => $invoice_id,
        'remote_state' => $status,
      ]);
      $payment->save();
      \Drupal::logger('commerce_btcpay')->debug('Created new payment @payment_id', ['@payment_id' => $payment->id()]);
    }

    return $payment;
  }

  /**
   * Safely convert a value to string for logging.
   *
   * @param mixed $value
   *   The value to convert.
   *
   * @return string
   *   The string representation.
   */
  protected function safeLogValue($value): string {
    if ($value === NULL) {
      return 'NULL';
    }
    if (is_bool($value)) {
      return $value ? 'TRUE' : 'FALSE';
    }
    if (is_array($value) || is_object($value)) {
      return print_r($value, TRUE);
    }
    return (string) $value;
  }

  /**
   * Map BTCPay invoice status to Commerce payment state.
   *
   * @param string $status
   *   The BTCPay invoice status.
   *
   * @return string
   *   The Commerce payment state.
   */
  protected function mapInvoiceStatus(string $status): string {
    $status_map = [
      'New' => 'new',
      'Processing' => 'authorization',
      'Settled' => 'completed',
      'Expired' => 'authorization_expired',
      'Invalid' => 'authorization_voided',
    ];

    return $status_map[$status] ?? 'new';
  }

  /**
   * Verify webhook signature.
   *
   * @param string $payload
   *   The webhook payload.
   * @param string $signature
   *   The signature header.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  protected function verifyWebhookSignature(string $payload, ?string $signature): bool {
    if (empty($signature) || empty($this->configuration['webhook_secret'])) {
      return FALSE;
    }

    $expected = hash_hmac('sha256', $payload, $this->configuration['webhook_secret']);
    
    // Extract the signature from the header (format: sha256=xxx).
    if (preg_match('/sha256=([a-f0-9]+)/', $signature, $matches)) {
      return hash_equals($expected, $matches[1]);
    }

    return FALSE;
  }

  /**
   * Redirect to previous checkout step on payment error.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  protected function redirectOnPaymentError(OrderInterface $order) {
    $this->messenger()->addError(
      $this->t('The payment could not be completed due to an expired or invalid invoice. Please try again or change payment option in the previous step.')
    );

    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
    $checkout_flow = $order->get('checkout_flow')->entity;
    if ($checkout_flow) {
      $checkout_flow_plugin = $checkout_flow->getPlugin();
      $step_id = $this->checkoutOrderManager->getCheckoutStepId($order);
      $previous_step_id = $checkout_flow_plugin->getPreviousStepId($step_id);
      $checkout_flow_plugin->redirectToStep($previous_step_id);
    }
  }

}
