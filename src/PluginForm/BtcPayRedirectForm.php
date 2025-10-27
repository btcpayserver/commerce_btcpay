<?php

namespace Drupal\commerce_btcpay\PluginForm;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides the off-site payment form for BTCPay.
 */
class BtcPayRedirectForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    
    \Drupal::messenger()->addMessage('BTCPay redirect form is being built...', 'status');

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

    $invoice = $payment_gateway_plugin->createInvoice($order, $options);
    
    if (!$invoice) {
      throw new PaymentGatewayException('Failed to create invoice on BTCPay Server.');
    }

    // Store invoice data on the order and payment.
    $invoice_data = $invoice->getData();
    $order->setData('btcpay', [
      'invoice_id' => $invoice_data['id'],
      'checkout_link' => $invoice_data['checkoutLink'],
      'status' => $invoice_data['status'],
      'created_time' => $invoice_data['createdTime'] ?? time(),
    ]);
    $order->save();
    
    // Update the payment with the remote ID.
    $payment->setRemoteId($invoice_data['id']);
    $payment->setRemoteState($invoice_data['status']);
    $payment->save();

    // Get the checkout URL.
    $redirect_url = $invoice_data['checkoutLink'];
    
    \Drupal::logger('commerce_btcpay')->info('Redirecting to BTCPay checkout: @url', [
      '@url' => $redirect_url,
    ]);

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
