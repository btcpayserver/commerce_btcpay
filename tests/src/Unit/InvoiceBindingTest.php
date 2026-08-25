<?php

namespace Drupal\Tests\commerce_btcpay\Unit;

use BTCPayServer\Result\Invoice;
use Drupal\commerce_btcpay\Plugin\Commerce\PaymentGateway\BtcPayRedirect;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests immutable invoice-to-payment binding checks.
 */
#[CoversClass(BtcPayRedirect::class)]
#[Group('commerce_btcpay')]
final class InvoiceBindingTest extends UnitTestCase {

  /**
   * Tests an exact invoice/payment binding.
   */
  public function testExactBinding(): void {
    [$plugin, $payment, $order, $invoice] = $this->createBindingFixture();
    $this->invokeBinding($plugin, $payment, $invoice, $order);
    $this->addToAssertionCount(1);
  }

  /**
   * Tests that a cheaper remote invoice cannot credit a larger payment.
   */
  public function testAmountMismatchIsRejected(): void {
    [$plugin, $payment, $order] = $this->createBindingFixture();
    $invoice = $this->createInvoice('9.99', 'EUR', '42');

    $this->expectException(\UnexpectedValueException::class);
    $this->invokeBinding($plugin, $payment, $invoice, $order);
  }

  /**
   * Tests that a remote invoice in another currency cannot be credited.
   */
  public function testCurrencyMismatchIsRejected(): void {
    [$plugin, $payment, $order] = $this->createBindingFixture();
    $invoice = $this->createInvoice('10.00', 'USD', '42');

    $this->expectException(\UnexpectedValueException::class);
    $this->invokeBinding($plugin, $payment, $invoice, $order);
  }

  /**
   * Tests that a remote invoice from another store cannot be credited.
   */
  public function testStoreMismatchIsRejected(): void {
    [$plugin, $payment, $order] = $this->createBindingFixture();
    $invoice = $this->createInvoice('10.00', 'EUR', '42', 'other-store');

    $this->expectException(\UnexpectedValueException::class);
    $this->invokeBinding($plugin, $payment, $invoice, $order);
  }

  /**
   * Tests that a metadata order ID cannot redirect credit to another order.
   */
  public function testOrderMismatchIsRejected(): void {
    [$plugin, $payment, $order] = $this->createBindingFixture();
    $invoice = $this->createInvoice('10.00', 'EUR', '99');

    $this->expectException(\UnexpectedValueException::class);
    $this->invokeBinding($plugin, $payment, $invoice, $order);
  }

  /**
   * Tests that an invoice cannot cross-credit another payment gateway.
   */
  public function testGatewayMismatchIsRejected(): void {
    [$plugin, , $order, $invoice] = $this->createBindingFixture();
    $payment = $this->createPayment($order, 'other-gateway');

    $this->expectException(\UnexpectedValueException::class);
    $this->invokeBinding($plugin, $payment, $invoice, $order);
  }

  /**
   * Creates a valid plugin, payment, order, and invoice fixture.
   */
  private function createBindingFixture(): array {
    $reflection = new \ReflectionClass(BtcPayRedirect::class);
    $plugin = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('activeGatewayId')->setValue($plugin, 'gateway');
    $reflection->getProperty('configuration')->setValue($plugin, ['store_id' => 'store']);

    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn('42');
    $payment = $this->createPayment($order, 'gateway');
    $invoice = $this->createInvoice('10.00', 'EUR', '42');
    return [$plugin, $payment, $order, $invoice];
  }

  /**
   * Creates a local payment mock.
   */
  private function createPayment(OrderInterface $order, string $gateway_id): PaymentInterface {
    $payment = $this->createMock(PaymentInterface::class);
    $payment->method('getRemoteId')->willReturn('invoice');
    $payment->method('getPaymentGatewayId')->willReturn($gateway_id);
    $payment->method('getOrder')->willReturn($order);
    $payment->method('getAmount')->willReturn(new Price('10.00', 'EUR'));
    return $payment;
  }

  /**
   * Creates authoritative BTCPay invoice data.
   */
  private function createInvoice(
    string $amount,
    string $currency,
    string $order_id,
    string $store_id = 'store',
  ): Invoice {
    return new Invoice([
      'id' => 'invoice',
      'amount' => $amount,
      'currency' => $currency,
      'storeId' => $store_id,
      'metadata' => ['orderId' => $order_id],
    ]);
  }

  /**
   * Invokes the protected binding assertion.
   */
  private function invokeBinding(
    BtcPayRedirect $plugin,
    PaymentInterface $payment,
    Invoice $invoice,
    OrderInterface $order,
  ): void {
    $method = (new \ReflectionClass(BtcPayRedirect::class))->getMethod('assertInvoiceBinding');
    $method->invoke($plugin, $payment, $invoice, $order);
  }

}
