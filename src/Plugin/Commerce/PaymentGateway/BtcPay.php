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
use Drupal\Component\Utility\Crypt;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
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
    \Drupal::logger('commerce_btcpay')->warning(print_r($request->getContent(), TRUE));

    // Get BTCPay payment data from order.
    $order_btcpay_data = $order->getData('btcpay');
    if (empty($order_btcpay_data['invoice_id'])) {
      throw new PaymentGatewayException('Invoice id missing for this BTCPay transaction.');
    }

    // As original BitPay API has no tokens to verfiy the counterparty server, we
    // need to query the invoice state to ensure it is payed.
    if ($this->checkInvoicePaidFull($order_btcpay_data['invoice_id']) === FALSE) {
      // TODO: check to silently fail, display message and redirect back to cart.
      throw new PaymentGatewayException('Invoice has not been fully paid.');
    }

    // If the user is anonymous and they provided the email during payment, add it to the order.
    if (empty($order->mail)) {
      $order->setEmail($request->query->get('buyerEmail'));
    }
    $order->save();

    // Init payment storage.
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');

    // Check if the IPN callback (onNotify) already created a payment entry.
    if (!empty($payments = $payment_storage->loadByProperties(['order_id' => $order->id()]))) { // change to invoice id?
      $payment = array_pop($payments);
      $payment->setState('completed');
      $payment->setRemoteState($request->query->get('status'));
      $payment->save();

    } else {
      // As no payment for that order ID exists create a new one.
      $payment = $payment_storage->create([
        'state' => 'completed',
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $this->entityId,
        'order_id' => $order->id(),
        'remote_id' => $order_btcpay_data['invoice_id'],
        'remote_state' => $order_btcpay_data['status'],
      ]);
      $payment->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    \Drupal::logger('commerce_btcpay')->notice(print_r($request->getContent(), TRUE));

    // Get BTCPay payment data from order.
    $order_btcpay_data = $order->getData('btcpay');
    if (empty($order_btcpay_data['invoice_id'])) {
      throw new PaymentGatewayException('Invoice id missing for this BTCPay transaction.');
    }

    // As original BitPay API has no tokens to verfiy the counterparty server, we
    // need to query the invoice state to ensure it is payed.
    if ($this->checkInvoicePaidFull($order_btcpay_data['invoice_id']) === FALSE) {
      // TODO: check to silently fail, display message and redirect back to cart.
      throw new PaymentGatewayException('onNotifiy: Invoice has not been fully paid.');
    }

    // Get bitpay payment data from order.
    $order_btcpay_data = $order->getData('btcpay');
    if (empty($order_btcpay_data['invoice_id'])) {
      throw new PaymentGatewayException('Invoice id missing for this BTCPay transaction.');
    }

    // Init payment storage.
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');

    // Check if the IPN callback (onNotify) already created a payment entry.
    if (!empty($payments = $payment_storage->loadByProperties(['order_id' => $order->id()]))) {
      $payment = array_pop($payments);
      $payment->setState('completed');
      $payment->setRemoteState($request->query->get('status'));
      $payment->save();

    } else {
      // As no payment for that order ID exists create a new one.
      $payment = $payment_storage->create([
        'state' => 'completed',
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $this->entityId,
        'order_id' => $order->id(),
        'remote_id' => $order_btcpay_data['invoice_id'],
        'remote_state' => $order_btcpay_data['status'],
      ]);
      $payment->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    // TODO: not sure if needed...
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    // Add a built in test for testing decline exceptions.
    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $billing_address */
    if ($billing_address = $payment_method->getBillingProfile()) {
      $billing_address = $payment_method->getBillingProfile()->get('address')->first();
      if ($billing_address->getPostalCode() == '53140') {
        throw new HardDeclineException('The payment was declined');
      }
    }

    // Perform the create payment request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    // Remember to take into account $capture when performing the request.
    $amount = $payment->getAmount();
    $payment_method_token = $payment_method->getRemoteId();
    // The remote ID returned by the request.
    $remote_id = '123456';
    $next_state = $capture ? 'completed' : 'authorization';

    $payment->setState($next_state);
    $payment->setRemoteId($remote_id);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    // TODO: implement if needed
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    // Perform the capture request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    $remote_id = $payment->getRemoteId();
    $number = $amount->getNumber();

    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    // TODO: implement if needed
    $this->assertPaymentState($payment, ['authorization']);
    // Perform the void request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    $remote_id = $payment->getRemoteId();

    $payment->setState('authorization_voided');
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    // TODO: implement if needed
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    // Perform the refund request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    $remote_id = $payment->getRemoteId();
    $number = $amount->getNumber();

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
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

    // Create BitPay client and send invoice data to payment backend.
    //$bitpay = \Drupal::getContainer()->get('bitpay.service');
    //$client = $bitpay->getClient();

    try {
      $client = $this->getBtcPayClient();
      return $client->createInvoice($invoice);
    } catch (\Exception $e) {
      // TODO: log
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
  public function checkInvoicePaidFull($invoiceId) {
    $confirmedStates = ['complete', 'paid'];

    $client = $this->getBtcPayClient();
    $invoice = $client->getInvoice($invoiceId);
    if (in_array($invoice->getStatus(), $confirmedStates)) {
      return TRUE;
    }

    return FALSE;
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

}
