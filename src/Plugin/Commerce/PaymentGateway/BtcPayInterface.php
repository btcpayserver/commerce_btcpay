<?php

namespace Drupal\commerce_btcpay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;

/**
 * Provides the interface for the BTCPay payment gateway.
 */
interface BtcPayInterface extends OffsitePaymentGatewayInterface {

  /**
   * Gets the BTCPay Greenfield API client.
   *
   * @return \BTCPayServer\Client\Invoice|null
   *   Returns the invoice client or NULL.
   */
  public function getApiClient();

  /**
   * Creates an invoice on BTCPay server.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param array $options
   *   Optional data like redirect url etc.
   *
   * @return \BTCPayServer\Result\Invoice|null
   *   The created invoice or NULL on failure.
   */
  public function createInvoice(OrderInterface $order, array $options = []);

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

}
