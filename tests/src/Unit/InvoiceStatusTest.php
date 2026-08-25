<?php

namespace Drupal\Tests\commerce_btcpay\Unit;

use Drupal\commerce_btcpay\InvoiceStatus;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests strict invoice-state decisions and monotonic transitions.
 */
#[CoversClass(InvoiceStatus::class)]
#[Group('commerce_btcpay')]
final class InvoiceStatusTest extends UnitTestCase {

  /**
   * Tests which authoritative invoice states may advance checkout.
   */
  #[DataProvider('checkoutStatusProvider')]
  public function testCheckoutCompletion(array $invoice, bool $expected): void {
    $this->assertSame($expected, InvoiceStatus::isCheckoutComplete($invoice));
  }

  /**
   * Provides invoice states for checkout completion tests.
   */
  public static function checkoutStatusProvider(): array {
    return [
      'settled' => [['status' => 'Settled', 'additionalStatus' => 'None'], TRUE],
      'overpaid and settled' => [['status' => 'Settled', 'additionalStatus' => 'PaidOver'], TRUE],
      'manually settled' => [['status' => 'Settled', 'additionalStatus' => 'Marked'], TRUE],
      'new' => [['status' => 'New', 'additionalStatus' => 'None'], FALSE],
      'processing' => [['status' => 'Processing', 'additionalStatus' => 'None'], FALSE],
      'partial' => [['status' => 'Processing', 'additionalStatus' => 'PaidPartial'], FALSE],
      'expired late payment' => [['status' => 'Expired', 'additionalStatus' => 'PaidLate'], FALSE],
      'invalid' => [['status' => 'Invalid', 'additionalStatus' => 'Invalid'], FALSE],
      'unknown status' => [['status' => 'Surprise', 'additionalStatus' => 'None'], FALSE],
      'unknown additional status' => [['status' => 'Settled', 'additionalStatus' => 'Surprise'], FALSE],
      'missing additional status' => [['status' => 'Settled'], FALSE],
    ];
  }

  /**
   * Tests safe authoritative-state mapping.
   */
  #[DataProvider('paymentStateProvider')]
  public function testPaymentState(array $invoice, ?string $expected): void {
    $this->assertSame($expected, InvoiceStatus::paymentState($invoice));
  }

  /**
   * Provides invoice data and expected Commerce payment states.
   */
  public static function paymentStateProvider(): array {
    return [
      'new' => [['status' => 'New'], 'new'],
      'processing' => [['status' => 'Processing'], 'authorization'],
      'partial' => [['status' => 'New', 'additionalStatus' => 'PaidPartial'], 'authorization'],
      'settled' => [['status' => 'Settled', 'additionalStatus' => 'None'], 'completed'],
      'expired' => [['status' => 'Expired'], 'authorization_expired'],
      'invalid' => [['status' => 'Invalid'], 'authorization_voided'],
      'unknown' => [['status' => 'Surprise'], NULL],
      'unsafe settled detail' => [['status' => 'Settled', 'additionalStatus' => 'PaidLate'], NULL],
    ];
  }

  /**
   * Tests monotonic payment transitions.
   */
  public function testMonotonicTransitions(): void {
    $this->assertTrue(InvoiceStatus::canTransition('new', 'authorization'));
    $this->assertTrue(InvoiceStatus::canTransition('new', 'completed'));
    $this->assertTrue(InvoiceStatus::canTransition('authorization', 'completed'));
    $this->assertTrue(InvoiceStatus::canTransition('completed', 'completed'));
    $this->assertFalse(InvoiceStatus::canTransition('completed', 'authorization'));
    $this->assertFalse(InvoiceStatus::canTransition('authorization_expired', 'completed'));
    $this->assertFalse(InvoiceStatus::canTransition('authorization', 'new'));
  }

}
