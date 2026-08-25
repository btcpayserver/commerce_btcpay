<?php

namespace Drupal\commerce_btcpay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;

/**
 * Provides the interface for the BTCPay payment gateway.
 */
interface BtcPayInterface extends OffsitePaymentGatewayInterface {

  /**
   * Gets the BTCPay Greenfield API invoice client.
   *
   * @return \BTCPayServer\Client\Invoice|null
   *   Returns the invoice client or NULL.
   */
  public function getInvoiceClient();

  /**
   * Creates an invoice on BTCPay server.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment entity containing the immutable amount to invoice.
   * @param array $options
   *   Optional data like redirect url etc.
   *
   * @return \BTCPayServer\Result\Invoice|null
   *   The created invoice or NULL on failure.
   */
  public function createInvoice(PaymentInterface $payment, array $options = []);

  /**
   * Get BTCPay invoice details.
   *
   * @param string $invoiceId
   *   The remote invoice ID.
   *
   * @return \BTCPayServer\Result\Invoice|null
   *   The queried invoice or NULL.
   */
  public function getInvoice(string $invoiceId);

  /**
   * Creates or updates the signed BTCPay webhook.
   *
   * @param string|null $gateway_id
   *   The existing payment gateway ID.
   *
   * @return bool
   *   TRUE when the webhook was configured successfully.
   */
  public function setupWebhook(?string $gateway_id = NULL): bool;

}
