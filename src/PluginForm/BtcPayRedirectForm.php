<?php

namespace Drupal\commerce_btcpay\PluginForm;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides the off-site payment form for BTCPay.
 */
class BtcPayRedirectForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    /** @var \Drupal\commerce_btcpay\Plugin\Commerce\PaymentGateway\BtcPayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();

    // Create the invoice on BTCPay Server.
    $options = [
      'return_url' => $form['#return_url'],
      'cancel_url' => $form['#cancel_url'],
    ];

    // The payment amount is the order's immutable remaining balance at the
    // time Commerce starts this payment flow.
    $invoice = $payment_gateway_plugin->createInvoice($payment, $options);

    if (!$invoice) {
      throw new PaymentGatewayException('Failed to create invoice on BTCPay Server.');
    }

    // Bind the existing local payment to the remote invoice before redirecting.
    $invoice_data = $invoice->getData();
    if (empty($invoice_data['id']) || empty($invoice_data['checkoutLink']) || empty($invoice_data['status'])) {
      throw PaymentGatewayException::createForPayment($payment, 'BTCPay Server returned an incomplete invoice.');
    }
    $payment->setRemoteId($invoice_data['id']);
    $payment->setRemoteState($invoice_data['status']);
    $payment->save();

    // The order copy is used only to locate the invoice on customer return.
    // Webhooks resolve and validate the payment by remote invoice ID.
    $order->setData('btcpay', [
      'invoice_id' => $invoice_data['id'],
    ]);
    $order->save();

    // Get the checkout URL.
    $redirect_url = $invoice_data['checkoutLink'];

    // Use buildRedirectForm to create the redirect.
    // For GET redirects, we pass the URL and empty data array.
    return $this->buildRedirectForm(
      $form,
      $form_state,
      $redirect_url,
      [],
      self::REDIRECT_GET
    );
  }

}
