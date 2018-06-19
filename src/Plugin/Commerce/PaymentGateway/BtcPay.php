<?php

namespace Drupal\commerce_btcpay\Plugin\Commerce\PaymentGateway;

use Bitpay\Bitpay;
use Bitpay\Client\Adapter\CurlAdapter;
use Bitpay\PrivateKey;
use Bitpay\PublicKey;
use Bitpay\SinKey;
use Bitpay\Client\Client;
use Bitpay\Network\Customnet;
use Bitpay\Storage\EncryptedFilesystemStorage;
use Bitpay\Token;
use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_checkout\CheckoutOrderManagerInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
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
 *   }
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
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, LoggerInterface $logger, CheckoutOrderManagerInterface $checkout_order_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->logger = $logger;
    $this->checkoutOrderManager = $checkout_order_manager;
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
      $container->get('commerce_checkout.checkout_order_manager')
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
        'minimum_payment_state' => 'confirmed',
        'debug_log' => NULL,
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['server_livenet'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live server host'),
      '#description' => $this->t('Enter a custom live server (without leading https://) here, e.g. <strong>btcpay.domain.tld</strong>. Make sure the server is working with https:// and has a valid SSL certificate.'),
      '#default_value' => $this->configuration['server_livenet'],
      '#states' => [
        'visible' => [
          ':input[name="configuration[btcpay_redirect][mode]"]' => ['value' => 'live']
        ]
      ]
    ];
    $form['pairing_code_livenet'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live server pairing code'),
      '#description' => $this->t('Visit your Manage API Tokens page (on your <strong>btcpay.domain.tld</strong>), click the "Add New Token" button, leave the "Require Authentication" checkbox checked, and enter the pairing code here.'),
      '#default_value' => $this->configuration['pairing_code_livenet'],
      '#states' => [
        'visible' => [
          ':input[name="configuration[btcpay_redirect][mode]"]' => ['value' => 'live']
        ]
      ]
    ];
    $form['token_livenet'] = [
      '#type' => 'item',
      '#title' => $this->t('Live API token status'),
      '#description' => $this->configuration['token_livenet'] ? $this->t('Configured') : $this->t('Not configured'),
      '#states' => [
        'visible' => [
          ':input[name="configuration[btcpay_redirect][mode]"]' => ['value' => 'live']
        ]
      ]
    ];
    $form['server_testnet'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test server host'),
      '#description' => $this->t('Enter a custom test server (without leading https://) here, e.g. <strong>btcpay.domain.tld</strong>. Make sure the server is working with https:// and has a valid SSL certificate.'),
      '#default_value' => $this->configuration['server_testnet'],
      '#states' => [
        'visible' => [
          ':input[name="configuration[btcpay_redirect][mode]"]' => ['value' => 'test']
        ]
      ]
    ];
    $form['pairing_code_testnet'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test pairing code'),
      '#description' => $this->t('Visit your Manage API Tokens page (on your <strong>btcpay.domain.tld</strong>), click the "Add New Token" button, leave the "Require Authentication" checkbox checked, and enter the pairing code here.'),
      '#default_value' => $this->configuration['pairing_code_testnet'],
      '#states' => [
        'visible' => [
          ':input[name="configuration[btcpay_redirect][mode]"]' => ['value' => 'test']
        ]
      ]
    ];
    $form['token_testnet'] = [
      '#type' => 'item',
      '#title' => $this->t('Test API token status'),
      '#description' => $this->configuration['token_testnet'] ? $this->t('Configured') : $this->t('Not configured'),
      '#states' => [
        'visible' => [
          ':input[name="configuration[btcpay_redirect][mode]"]' => ['value' => 'test']
        ]
      ]
    ];
    $form['minimum_payment_state'] = [
      '#type' => 'select',
      '#title' => $this->t('Minimum remote payment state'),
      '#description' => $this->t('Choose after which BTCPay payment state you accept a payment as fully paid ("paid": 0-confirmations (only for small sums, danger of double spends), "confirmed": at least 1 confirmation, "complete" at least 6 confirmations. Note: Lightning Network payments are always assumed "complete" as they settle immediately.'),
      '#default_value' => $this->configuration['minimum_payment_state'],
      '#options' => [
        'paid' => $this->t('Paid'),
        'confirmed' => $this->t('Confirmed'),
        'complete' => $this->t('Complete')
      ],
    ];
    $form['debug_log'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Enable verbose logging for debugging.'),
      '#return_value' => '1',
      '#default_value' => $this->configuration['debug_log'],
    );

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
      $this->configuration['minimum_payment_state'] = $values['minimum_payment_state'];
      $this->configuration['debug_log'] = $values['debug_log'];
      $this->configuration['mode'] = $values['mode'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    // TODO: if you only change eg. hostname of server, tokens get overwritten in $this->configuration...
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['server_livenet'] = $values['server_livenet'];
      $this->configuration['pairing_code_livenet'] = '';
      $this->configuration['server_testnet'] = $values['server_testnet'];
      $this->configuration['pairing_code_testnet'] = '';
      $this->configuration['minimum_payment_state'] = $values['minimum_payment_state'];
      $this->configuration['debug_log'] = $values['debug_log'];

      // Create keys and tokens on BTCPay Server.
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

    // As original BitPay API has no tokens to verfiy the counterparty server, we
    // need to query the invoice state to ensure it is payed.
    if ( ! $invoice = $this->getInvoice($order_btcpay_data['invoice_id'])) {
      // TODO: check to silently fail, display message and redirect back to cart.
      throw new PaymentGatewayException('Invoice not found.');
    }

    // If the user is anonymous and they provided the email during payment, add it to the order.
    if (empty($order->mail)) {
      $order->setEmail($request->query->get('buyerEmail'));
    }
    $order->save();

    $this->processPayment($invoice);
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    $this->logger->debug(print_r($request->getContent(), TRUE));

    if (! $responseData = json_decode($request->getContent(), TRUE)) {
      throw new PaymentGatewayException('Response data missing, aborting.');
    }

    if (empty($responseData['id'])) {
      throw new PaymentGatewayException('Invoice id missing for this BTCPay transaction, aborting.');
    }

    // Load the invoice data from remote server to verify the payment.
    /** @var \Bitpay\Invoice $invoice */
    $invoice = $this->getInvoice($responseData['id']);
    if (empty($invoice)) {
      throw new PaymentGatewayException('Invoice not found on BTCPay server.');
    }

    // As original BitPay API has no tokens to verify the counterparty server, we
    // need to query the invoice state to ensure it is payed.
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    if (! $order = \Drupal\commerce_order\Entity\Order::load($invoice->getOrderId())) {
      throw new PaymentGatewayException('Could not find matching order.');
    }

    // Set the order to completed state if there is an payment ongoing.
    if ($payment = $this->processPayment($invoice)) {
      /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
      $checkout_flow = $order->get('checkout_flow')->entity;
      $checkout_flow_plugin = $checkout_flow->getPlugin();
      $checkout_flow_plugin->setOrder($order);
      $step_id = $this->checkoutOrderManager->getCheckoutStepId($order);
      $next_step_id = $checkout_flow_plugin->getNextStepId($step_id);

      try {
        $checkout_flow_plugin->redirectToStep($next_step_id);
      }
      catch (NeedsRedirectException $exception) {
        // Do nothing to avoid redirection.
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function processPayment($invoice) {
    // Init payment storage.
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');

    // Load the order
    /** @var OrderInterface $order */
    if (! $order = \Drupal\commerce_order\Entity\Order::load($invoice->getOrderId())) {
      throw new PaymentGatewayException('processPayment: Could not find matching order.');
    }

    // Only continue if the payment status of the invoice has valid payment state.
    // TODO: handle invalidated payments + refunds
    if ($this->checkInvoicePaidFull($invoice) === FALSE) {
      // TODO: Log invoice state indicating problem with payment.
      return NULL;
    }

    $paymentState = $this->mapRemotePaymentState($invoice->getStatus());

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    // Check if the IPN callback (onNotify) already created a payment entry.
    if (!empty($payments = $payment_storage->loadByProperties(['order_id' => $order->id(), 'remote_id' => $invoice->getId()]))) {
      $payment = array_pop($payments);
      $payment->setState($paymentState);
      $payment->setRemoteState($invoice->getStatus());
      $payment->save();

    } else {
      // As no payment for that order ID exists create a new one.
      $payment = $payment_storage->create([
        'state' => $paymentState,
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $this->entityId,
        'order_id' => $order->id(),
        'remote_id' => $invoice->getId(),
        'remote_state' => $invoice->getStatus(),
      ]);
      $payment->save();
    }

    return (! empty($payment)) ? $payment : NULL;
  }


  /**
   * {@inheritdoc}
   */
  protected function mapRemotePaymentState($remoteState) {
    // Config option: dropdown payment full
    // "0conf" is 0-conf payment (tx visible on blockchain)
    // "1conf" at least 1 confirmation
    // "6conf" at least 6 confirmations

    // TODO: handle invalidated/refunded payments.

    $mappedState = '';

    switch ($this->configuration['minimum_payment_state']) {
      case "paid":
        if (in_array($remoteState, ["paid", "confirmed", "complete"])) {
          $mappedState = "completed";
        } else {
          $mappedState = "authorization";
        }
        break;
      case "confirmed":
        if (in_array($remoteState, ["confirmed", "complete"])) {
          $mappedState = "completed";
        } else {
          $mappedState = "authorization";
        }
        break;
      case "complete":
        if ($remoteState == "complete") {
          $mappedState = "completed";
        } else {
          $mappedState = "authorization";
        }
        break;
    }

    return $mappedState;
  }

  /**
   * {@inheritdoc}
   */
  public function getServerUrl() {
    if ($this->getMode() === 'live') {
      return $this->configuration['server_livenet'];
    } else {
      return $this->configuration['server_testnet'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createInvoice(OrderInterface $order = NULL, $options = []) {
    $invoice = new \Bitpay\Invoice;
    $currency = new \Bitpay\Currency();
    $currency->setCode($order->getTotalPrice()->getCurrencyCode());
    $invoice->setCurrency($currency);
    $invoice->setPrice($order->getTotalPrice()->getNumber());
    $invoice->setPaymentTotals($order->getTotalPrice()->getNumber());
    $invoice->setOrderId($order->id());
    $invoice->setPosData($order->id());

    // As bitpay API currently supports only one item we set it to the store name.
    $item = new \Bitpay\Item();
    $entity_manager = \Drupal::entityTypeManager();
    $store = $entity_manager->getStorage('commerce_store')->load($order->getStoreId());
    $item->setDescription($store->getName());
    $item->setPrice($order->getTotalPrice()->getNumber());
    $invoice->setItem($item);

    // Add buyer data.
    $buyer = new \Bitpay\Buyer();
    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $billing_address */
    $billing_address = $order->getBillingProfile()->get('address')->first();
    $buyer->setFirstName($billing_address->getGivenName())
      ->setLastName($billing_address->getFamilyName())
      ->setEmail($order->getEmail())
      ->setAddress([
        $billing_address->getAddressLine1(),
        $billing_address->getAddressLine2(),
      ])
      ->setCity($billing_address->getLocality())
      ->setState($billing_address->getAdministrativeArea())
      ->setZip($billing_address->getPostalCode())
      ->setCountry($billing_address->getCountryCode());

    $invoice->setBuyer($buyer);

    // Set return url (where external payment provider should redirect to).
    $invoice->setRedirectUrl($options['return_url']);
    // Set notification url.
    $invoice->setNotificationUrl($this->getNotifyUrl()->toString());

    try {
      $client = $this->getBtcPayClient();
      return $client->createInvoice($invoice);
    } catch (\Exception $e) {
      // TODO: log
      drupal_set_message(t(
        'Error creating payment on remote server. Error: @error',
        ['@error' => $e->getMessage()]
      ),
        'error');
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getInvoice($invoiceId) {
    $client = $this->getBtcPayClient();
    return $client->getInvoice($invoiceId);
  }

  /**
   * {@inheritdoc}
   */
  public function checkInvoicePaidFull($invoice) {
    $confirmedStates = ['paid', 'confirmed', 'complete'];

    return in_array($invoice->getStatus(), $confirmedStates);
  }

  /**
   * {@inheritdoc}
   */
  public function createToken($network, $pairing_code) {
    // TODO: refactor, not sure what the point is to instantiate Bitpay class,
    // seems only used for config variables set/get/only keymanager but not private
    // keys. Or other way around use it also for api invoice requests.

    global $base_url;
    $this->configuration["token_$network"] = '';
    $this->configuration["private_key_password_$network"] = '';
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
      /** @var \Bitpay\KeyManager $keyManager */
      $keyManager = $bitpay->get('key_manager');
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
      drupal_set_message($this->t('Failed to create key pair: %message', ['%message' => $e->getMessage()]), 'error');
      return;
    }
    // Create API access token.
    $sin = new SinKey();
    $sin->setPublicKey($publicKey);
    $sin->generate();
    $client = $bitpay->get('client');
    try {
      $token = $client->createToken([
        'id' => (string) $sin,
        'pairingCode' => $pairing_code,
        'label' => $base_url,
      ]);
    }
    catch (\Exception $e) {
      drupal_set_message($this->t('Failed to create @network token: %message', [
        '%message' => $e->getMessage(),
        '@network' => $network,
      ]), 'error');
      return;
    }
    $this->configuration["token_$network"] = (string) $token;
    $this->configuration["private_key_password_$network"] = $password;
    drupal_set_message($this->t('New @network API token generated successfully. Encrypted keypair saved to the private filesystem.', ['@network' => $network]));
  }

  /**
   * {@inheritdoc}
   */
  public function getBtcPayClient() {
    // TODO: refactor to use Bitpay class? with common config abstraction (getBtcPayService?())
    $network = $this->getMode() . 'net';

    try {
      $storageEngine = new EncryptedFilesystemStorage($this->configuration["private_key_password_$network"]);
      $privateKey = $storageEngine->load("private://btcpay_$network.key");
      $publicKey = $storageEngine->load("private://btcpay_$network.pub");

      $client = new Client();
      $remoteNetwork = new Customnet($this->getServerUrl(), 443); //inconsistent why have getServerUrl() not $this->configuration['']?
      $adapter = new CurlAdapter();
      $token = new Token();
      $token->setToken($this->configuration["token_$network"]);

      $client->setPrivateKey($privateKey);
      $client->setPublicKey($publicKey);
      $client->setNetwork($remoteNetwork);
      $client->setAdapter($adapter);
      $client->setToken($token);

      return $client;
    } catch (\Exception $e) {
      // TODO: log
      return NULL;

    }

  }

  /**
   * Check if verbose logging enabled.
   *
   * @return bool
   */
  protected function debugEnabled() {
    return $this->configuration['debug_log'] == 1 ? TRUE : FALSE;
  }

}
