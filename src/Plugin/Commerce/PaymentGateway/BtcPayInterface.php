<?php

namespace Drupal\commerce_btcpay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsNotificationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;

/**
 * Provides the interface for the BTCPay payment gateway.
 */
interface BtcPayInterface extends SupportsAuthorizationsInterface, SupportsRefundsInterface, SupportsNotificationsInterface {

  /**
   * Gets the server URL.
   *
   * @return string
   *   The server URL.
   */
  public function getServerUrl();

  /**
   * Creates and saves a key pair and token.
   *
   * @copyright Code heavily inspired from BitPay module: https://dgo.to/bitpay
   * @author Mark Burdett (mfb)
   *
   * @param string $network
   *   Network string we want to configure.
   * @param string $pairing_code
   *   Pairing code provided by btcpay server.
   *
   * @return void
   */
  public function createToken($network, $pairing_code);

  /**
   * Instantiate and return REST API Client.
   *
   * @return \Bitpay\Client\Client|NULL;
   *   Returns the client or NULL.
   */
  public function getBtcPayClient();

  /**
   * Creates an invoice on BTCPay server.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity, or null.
   * @param array $options
   *   Optional data like redirect url etc.
   *
   * @return \Bitpay\Invoice
   *   the created invoice.
   */
  public function createInvoice(OrderInterface $order = NULL, array $options = []);

  /**
   * Get BTCPay details to an existing invoice.
   *
   * Builds the data for the request and make the request.
   *
   * @param string $invoiceId
   *   The remote invoice ID.
   *
   * @return \Bitpay\Invoice|NULL
   *   The queried invoice or NULL.
   */
  public function getInvoice($invoiceId);

  /**
   * Check BTCPay server invoice and check status.
   *
   * @param string $invoiceId
   *
   * @return boolean
   */
  public function checkInvoicePaidFull($invoiceId);

}
