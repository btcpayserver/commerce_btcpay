<?php

namespace Drupal\commerce_btcpay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsNotificationsInterface;

/**
 * Provides the interface for the BTCPay payment gateway.
 */
interface BtcPayInterface extends SupportsNotificationsInterface {

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
   * @param \Bitpay\InvoiceInterface $invoice
   *
   * @return boolean
   */
  public function checkInvoicePaidFull($invoice);

  /**
   * Update/create payment for order.
   *
   * Handles creation or update of existing payment entities.
   *
   * @param \Bitpay\InvoiceInterface $invoice
   *   Remote BTCPay invoice.
   *
   * @return
   */
  public function processPayment($invoice);

  /**
   * Depending on the payment type settings decide the payment state.
   *
   * Depending on payment settings we handle 0-conf/confirmed/6-conf payments as "completed"
   *
   * TODO: think about how to handle overpayments (duplicate/multiple) payments? Create new payment or update old?
   *
   * @param string $remoteState
   *   Remote BTCPay invoice state.
   *
   * @return void
   */
  public function mapRemotePaymentState($remoteState);

}
