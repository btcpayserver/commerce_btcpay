<?php

namespace Drupal\commerce_btcpay\Plugin\Commerce\PaymentGateway;

use Bitpay\InvoiceInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsNotificationsInterface;

/**
 * Provides the interface for the BTCPay payment gateway.
 */
interface BtcPayInterface extends SupportsNotificationsInterface {

  /**
   * Gets the server URL.
   *
   * @return array $host
   *   Returns an array with host and port info: 1 => 'host', 2 => 'port'.
   *   Defaults to port 443.
   */
  public function getServerConfig();

  /**
   * Creates and saves a key pair and token.
   *
   * @copyright Code heavily inspired from BitPay module: https://dgo.to/bitpay
   * @author Mark Burdett (mfb)
   *
   * @param string $network
   *   Network string we want to configure.
   * @param string $pairing_code
   *   Pairing code provided by BTCPayServer.
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
   * Check BTCPay server invoice on payment error states.
   *
   * @param \Bitpay\InvoiceInterface $invoice
   *
   * @return boolean
   */
  public function checkInvoicePaymentFailed($invoice);

  /**
   * Check if a payment entity for a given order and invoice combination already
   * exists and return it or NULL if not.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity, or null.
   * @param \Bitpay\InvoiceInterface $invoice
   *   Remote BTCPay invoice.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|NULL
   */
  public function loadExistingPayment(OrderInterface $order, InvoiceInterface $invoice);

  /**
   * Handle payment error and redirect previous checkout step.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity, or null.
   * @param bool $nonInteractive
   *   Workaround param to handle redirects onNotify() which is non user facing.
   *
   *  @return void
   */
  public function redirectOnPaymentError(OrderInterface $order, $nonInteractive = FALSE);

  /**
   * Update/create payment for order.
   *
   * Handles creation or update of existing payment entities.
   *
   * @param \Bitpay\InvoiceInterface $invoice
   *   Remote BTCPay invoice.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|NULL
   */
  public function processPayment($invoice);

  /**
   * Map the remote payment state to some available Commerce payment state.
   *
   * TODO: think about how to handle overpayments (duplicate/multiple) payments? Create new payment or update old?
   * TODO: maybe add remote payment states to Commerce payment states?
   *
   * @param string $remoteState
   *   Remote BTCPay invoice state.
   *
   * @return void
   */
  public function mapRemotePaymentState($remoteState);

}
