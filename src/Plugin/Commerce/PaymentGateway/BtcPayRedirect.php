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
  public function getMode() {
    return null;
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
      'webhook_id' => '',
      'debug_mode' => FALSE,
      // Offsite gateways don't collect billing information or payment methods.
      'collect_billing_information' => FALSE,
      'payment_method_types' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Ensure configuration has default values before calling parent.
    $this->configuration += $this->defaultConfiguration();
    
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

    $form['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug Mode'),
      '#description' => $this->t('Enable verbose logging for debugging. Disable in production to reduce log entries.'),
      '#default_value' => $this->configuration['debug_mode'] ?? FALSE,
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
      $this->configuration['debug_mode'] = $values['debug_mode'] ?? FALSE;
      
      // Ensure offsite gateway settings are correct.
      $this->configuration['collect_billing_information'] = FALSE;
      $this->configuration['payment_method_types'] = [];
      
      // Setup webhook after saving configuration
      // Get the gateway entity ID from the form state
      $gateway = $form_state->getFormObject()->getEntity();
      $gateway_id = $gateway->id();
      
      if ($this->setupWebhook($gateway_id)) {
        \Drupal::messenger()->addStatus($this->t('Webhook configured successfully.'));
      }
      else {
        \Drupal::messenger()->addWarning($this->t('Could not configure webhook. Please check the logs.'));
      }

      // Persist the webhook_id and any configuration changes made during
      // setupWebhook() to the payment gateway entity immediately.
      // In the settings form context, ensure we save the configuration.
      if ($gateway && method_exists($gateway, 'setPluginConfiguration')) {
        $gateway->setPluginConfiguration($this->getConfiguration());
        $gateway->save();
      }
    }
  }

  /**
   * Sets up or updates the webhook for this payment gateway.
   * 
   * @param string|null $gateway_id
   *   The payment gateway entity ID. If not provided, will try to get from entityId.
   * 
   * @return bool
   *   TRUE if webhook was set up successfully, FALSE otherwise.
   */
  protected function setupWebhook($gateway_id = NULL) {
    // Get the gateway ID from parameter or from the entity
    if (!$gateway_id) {
      $gateway_id = $this->entityId ?? NULL;
    }
    
    if (!$gateway_id) {
      $this->logger->error('Cannot setup webhook: gateway ID not available.');
      return FALSE;
    }
    if (empty($this->configuration['server_url']) || empty($this->configuration['api_key']) || empty($this->configuration['store_id'])) {
      $this->logger->error('Cannot setup webhook: missing configuration.');
      return FALSE;
    }

    try {
      $webhook_client = new \BTCPayServer\Client\Webhook(
        $this->configuration['server_url'],
        $this->configuration['api_key']
      );

      // Build the webhook URL
      $webhook_url = \Drupal\Core\Url::fromRoute('commerce_btcpay.notify', [
        'commerce_payment_gateway' => $gateway_id,
      ], ['absolute' => TRUE])->toString();

      // Check if we have a stored webhook ID
      $webhook_id = $this->configuration['webhook_id'] ?? NULL;

      // Verify the stored webhook exists; otherwise, clear it so we can search by URL.
      if ($webhook_id) {
        try {
          $webhook_client->getWebhook($this->configuration['store_id'], $webhook_id);
          if ($this->configuration['debug_mode'] ?? FALSE) {
            $this->logger->info('Using stored webhook ID: @id', ['@id' => $webhook_id]);
          }
        }
        catch (\Throwable $e) {
          // Webhook doesn't exist anymore, reset and try to find by URL.
          if ($this->configuration['debug_mode'] ?? FALSE) {
            $this->logger->warning('Stored webhook @id not found on server, will search by URL.', ['@id' => $webhook_id]);
          }
          $webhook_id = NULL;
        }
      }

      // If we don't have a valid webhook ID, try to find an existing webhook by URL.
      if (!$webhook_id) {
        try {
          // Use getStoreWebhooks() which returns a WebhookList object
          $webhook_list = $webhook_client->getStoreWebhooks($this->configuration['store_id']);
          $existing_webhooks = $webhook_list->all(); // Returns array of Webhook result objects
          
          foreach ($existing_webhooks as $existing_webhook) {
            // Webhook result object has getUrl() and getId() methods
            $existing_url = $existing_webhook->getUrl();
            if ($existing_url === $webhook_url) {
              $webhook_id = $existing_webhook->getId();
              if ($this->configuration['debug_mode'] ?? FALSE) {
                $this->logger->info('Found existing webhook by URL with ID @id', ['@id' => $this->safeLogValue($webhook_id)]);
              }
              break;
            }
          }
        }
        catch (\Throwable $e) {
          // Non-fatal: If listing webhooks fails, we'll fall back to creating one.
          if ($this->configuration['debug_mode'] ?? FALSE) {
            $this->logger->warning('Could not list existing webhooks: @error', ['@error' => $e->getMessage()]);
          }
        }
      }

      // Ensure we have a webhook secret
      if (empty($this->configuration['webhook_secret'])) {
        $this->configuration['webhook_secret'] = bin2hex(random_bytes(32));
      }

      // Specific events we want to listen to
      $specific_events = [
        'InvoiceReceivedPayment',
        'InvoicePaymentSettled',
        'InvoiceProcessing',
        'InvoiceExpired',
        'InvoiceSettled',
        'InvoiceInvalid',
      ];

      if ($webhook_id) {
        // Update existing webhook.
        $webhook_client->updateWebhook(
          $this->configuration['store_id'],
          $webhook_url,
          $webhook_id,
          $specific_events,
          TRUE, // enabled
          TRUE, // automaticRedelivery
          $this->configuration['webhook_secret']
        );
        if ($this->configuration['debug_mode'] ?? FALSE) {
          $this->logger->info('Updated webhook @id for store @store', [
            '@id' => $webhook_id,
            '@store' => $this->configuration['store_id'],
          ]);
        }
        // Persist the ID to config to be safe.
        $this->configuration['webhook_id'] = $webhook_id;
      }
      else {
        // Create new webhook.
        $result = $webhook_client->createWebhook(
          $this->configuration['store_id'],
          $webhook_url,
          $specific_events,
          $this->configuration['webhook_secret'],
          TRUE, // enabled
          TRUE  // automaticRedelivery
        );

        // Store the webhook ID for future updates
        $data = is_object($result) && method_exists($result, 'getData') ? $result->getData() : (array) $result;
        $this->configuration['webhook_id'] = $data['id'] ?? NULL;

        if ($this->configuration['debug_mode'] ?? FALSE) {
          $this->logger->info('Created webhook @id for store @store', [
            '@id' => $this->safeLogValue($this->configuration['webhook_id']),
            '@store' => $this->configuration['store_id'],
          ]);
        }
      }

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Error setting up webhook: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
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
    
    // Log incoming webhook (only in debug mode)
    if ($this->configuration['debug_mode'] ?? FALSE) {
      $this->logger->info('BTCPay webhook received. Event type: @type', [
        '@type' => $data['type'] ?? 'unknown',
      ]);
    }
    
    // Validate webhook signature FIRST (before any other processing)
    // Note: Header names may vary in case (BTCPay-Sig vs btcpay-sig)
    $signature = $this->getWebhookSignature($request);
    
    if (!$this->validWebhookRequest($signature, $payload)) {
      $this->logger->error('BTCPay webhook: Failed to validate signature.');
      throw new PaymentGatewayException('Invalid webhook signature.');
    }
    
    // Validate payload structure
    if (empty($data['invoiceId'])) {
      $this->logger->error('BTCPay webhook: Invoice ID missing from payload.');
      return;
    }
    
    if ($this->configuration['debug_mode'] ?? FALSE) {
      $this->logger->info('Processing webhook for invoice: @invoice_id, event: @event', [
        '@invoice_id' => $data['invoiceId'],
        '@event' => $data['type'] ?? 'unknown',
      ]);
    }

    // Fetch fresh invoice data from BTCPay Server (don't trust webhook payload alone)
    $invoice = $this->getInvoice($data['invoiceId']);
    if (!$invoice) {
      $this->logger->error('BTCPay webhook: Could not retrieve invoice from BTCPay Server. Invoice ID: @invoice_id', [
        '@invoice_id' => $data['invoiceId'],
      ]);
      return;
    }

    // Get invoice data
    $invoice_data = $invoice->getData();
    if ($this->configuration['debug_mode'] ?? FALSE) {
      $this->logger->debug('BTCPay invoice status: @status, additional status: @additional', [
        '@status' => $invoice_data['status'] ?? 'unknown',
        '@additional' => $invoice_data['additionalStatus'] ?? 'none',
      ]);
    }

    // Get order ID from invoice metadata.
    if (empty($invoice_data['metadata']['orderId'])) {
      $this->logger->error('BTCPay webhook: Order ID missing from invoice metadata.');
      return;
    }
    
    $order_id = $invoice_data['metadata']['orderId'];

    // Load the order.
    $order_storage = \Drupal::entityTypeManager()->getStorage('commerce_order');
    $order = $order_storage->load($order_id);
    if (!$order) {
      $this->logger->error('BTCPay webhook: Order @order_id not found.', ['@order_id' => $order_id]);
      return;
    }

    // Process the invoice based on webhook event type
    $this->processWebhookEvent($order, $invoice, $data);
  }

  /**
   * Process webhook event and update order/payment accordingly.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \BTCPayServer\Result\Invoice $invoice
   *   The BTCPay invoice.
   * @param array $webhook_data
   *   The webhook payload data.
   */
  protected function processWebhookEvent(OrderInterface $order, InvoiceResult $invoice, array $webhook_data) {
    $invoice_data = $invoice->getData();
    $event_type = $webhook_data['type'] ?? 'unknown';
    $invoice_status = $invoice_data['status'] ?? 'Unknown';
    $additional_status = $invoice_data['additionalStatus'] ?? '';
    
    if ($this->configuration['debug_mode'] ?? FALSE) {
      $this->logger->info('Processing webhook event @event for order @order_id, invoice status: @status', [
        '@event' => $event_type,
        '@order_id' => $order->id(),
        '@status' => $invoice_status,
      ]);
    }
    
    // Determine payment state based on event type and invoice status
    $payment_state = NULL;
    $order_message = 'Event: ' . $event_type . ': ';
    
    switch ($event_type) {
      case 'InvoiceReceivedPayment':
        $payment_state = 'authorization';
        $order_message .= 'Received (partial) payment but waiting for settlement.';
        break;
        
      case 'InvoicePaymentSettled':
        // Only settled if the full invoice is paid
        if ($invoice_status === 'Expired' && $invoice->isPaidLate()) {
          $payment_state = 'completed';
          $order_message = 'Already expired invoice now fully paid and settled.';
        } else {
          $payment_state = 'authorization';
          $order_message .= '(Partial) payment now settled.';
        }
        break;
        
      case 'InvoiceProcessing':
        $payment_state = 'authorization';
        $order_message .= 'Received full payment but waiting for settlement.';
        break;
        
      case 'InvoiceSettled':
        $payment_state = 'completed';
        if ($additional_status === 'PaidOver') {
          $order_message = 'Overpaid and settled. Please check transaction for refund amount.';
        } else {
          $order_message = 'Fully paid and settled.';
        }
        break;
        
      case 'InvoiceExpired':
        $payment_state = 'authorization_expired';
        if (!empty($webhook_data['partiallyPaid'])) {
          $order_message .= 'Invoice expired but received partial payment. Please check transaction details.';
        } else {
          $order_message .= 'Invoice expired without payment.';
        }
        break;
        
      case 'InvoiceInvalid':
        $payment_state = 'authorization_voided';
        $order_message .= 'Invoice marked as invalid.';
        break;
        
      default:
        $this->logger->warning('Unhandled webhook event type: @type', ['@type' => $event_type]);
        return;
    }
    
    if ($payment_state) {
      // Update or create payment
      $this->updatePayment($order, $invoice, $payment_state);
      
      // Log the status update (always log successful updates)
      $this->logger->notice('Payment status updated for order @order_id: @message', [
        '@order_id' => $order->id(),
        '@message' => $order_message,
      ]);
    }
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
   * Update or create payment for an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \BTCPayServer\Result\Invoice $invoice
   *   The BTCPay invoice.
   * @param string $payment_state
   *   The payment state to set.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|null
   *   The payment entity or NULL.
   */
  protected function updatePayment(OrderInterface $order, InvoiceResult $invoice, string $payment_state) {
    $payment_storage = \Drupal::entityTypeManager()->getStorage('commerce_payment');
    $invoice_data = $invoice->getData();
    $invoice_id = $invoice_data['id'];
    $status = $invoice_data['status'];

    // Check if payment already exists
    $payments = $payment_storage->loadByProperties([
      'order_id' => $order->id(),
      'remote_id' => $invoice_id,
    ]);
    $payment = reset($payments);

    if ($payment) {
      // Update existing payment
      $payment->setState($payment_state);
      $payment->setRemoteState($status);
      $payment->save();
      if ($this->configuration['debug_mode'] ?? FALSE) {
        $this->logger->debug('Updated existing payment @payment_id to state @state', [
          '@payment_id' => $payment->id(),
          '@state' => $payment_state,
        ]);
      }
    }
    else {
      // Create new payment
      $payment_gateway_id = $this->parentEntity ? $this->parentEntity->id() : NULL;
      
      $payment = $payment_storage->create([
        'state' => $payment_state,
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $payment_gateway_id,
        'order_id' => $order->id(),
        'remote_id' => $invoice_id,
        'remote_state' => $status,
      ]);
      $payment->save();
      if ($this->configuration['debug_mode'] ?? FALSE) {
        $this->logger->debug('Created new payment @payment_id with state @state', [
          '@payment_id' => $payment->id(),
          '@state' => $payment_state,
        ]);
      }
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
   * Get webhook signature from request headers.
   * 
   * Note: Header names may be case-insensitive depending on the server.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return string|null
   *   The signature or NULL if not found.
   */
  protected function getWebhookSignature(Request $request): ?string {
    // Try different case variations of the header name
    $signature = $request->headers->get('BTCPay-Sig');
    if (!$signature) {
      $signature = $request->headers->get('btcpay-sig');
    }
    if (!$signature) {
      $signature = $request->headers->get('Btcpay-Sig');
    }
    return $signature;
  }

  /**
   * Validate webhook request signature.
   *
   * @param string|null $signature
   *   The signature from the header.
   * @param string $payload
   *   The raw webhook payload.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  protected function validWebhookRequest(?string $signature, string $payload): bool {
    if (empty($signature) || empty($this->configuration['webhook_secret'])) {
      $this->logger->warning('Webhook validation failed: missing signature or secret.');
      return FALSE;
    }

    // Use BTCPay SDK's validation method
    try {
      return Webhook::isIncomingWebhookRequestValid(
        $payload,
        $signature,
        $this->configuration['webhook_secret']
      );
    }
    catch (\Exception $e) {
      $this->logger->error('Webhook validation error: @error', ['@error' => $e->getMessage()]);
      return FALSE;
    }
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
