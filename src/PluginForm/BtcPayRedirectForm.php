<?php

namespace Drupal\commerce_btcpay\PluginForm;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

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

    // Simulate an API call failing and throwing an exception, for test purposes.
    // See PaymentCheckoutTest::testFailedCheckoutWithOffsiteRedirectGet().
    if ($order->getBillingProfile()->get('address')->family_name == 'TRIGGER FAIL') {
      throw new PaymentGatewayException('Could not get the redirect URL.');
    }

    // Create the invoice (payment request) on the BTCPay server.
    $options = [
      'return_url' => $form['#return_url'],
      'cancel_url' => $form['#cancel_url'],
    ];

    /** @var \Bitpay\Invoice $btcPayInvoice **/
    if (! $btcPayInvoice = $payment_gateway_plugin->createInvoice($order, $options)) {
      $this->redirectToPreviousStep();
    }

    // Store the remote invoice data on the order.
    $order->setData('btcpay', [
      'invoice_id' => $btcPayInvoice->getId(),
      'expiration_time' => $btcPayInvoice->getExpirationTime()->getTimestamp(),
      'status' => $btcPayInvoice->getStatus(),
    ]);
    $order->save();

    // Redirect url from payment provider.
    $redirect_url = $btcPayInvoice->getUrl();

    $data = [];

    return $this->buildRedirectForm($form, $form_state, $redirect_url, $data);
  }

  /**
   * Redirects to a previous checkout step on error.
   *
   * @throws \Drupal\commerce\Response\NeedsRedirectException
   */
  protected function redirectToPreviousStep() {
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();

    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
    $checkout_flow = $order->get('checkout_flow')->entity;
    /** @var \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowWithPanesInterface $checkout_flow_plugin */
    $checkout_flow_plugin = $checkout_flow->getPlugin();
    $step_id = $checkout_flow_plugin->getPane('payment_information')->getStepId();
    return $checkout_flow_plugin->redirectToStep($step_id);
  }

}
