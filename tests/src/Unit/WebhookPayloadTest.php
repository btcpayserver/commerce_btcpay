<?php

namespace Drupal\Tests\commerce_btcpay\Unit;

use Drupal\commerce_btcpay\Plugin\Commerce\PaymentGateway\BtcPayRedirect;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests strict BTCPay webhook envelope validation.
 */
#[CoversClass(BtcPayRedirect::class)]
#[Group('commerce_btcpay')]
final class WebhookPayloadTest extends UnitTestCase {

  /**
   * Tests normalization of all security-relevant envelope fields.
   */
  public function testValidPayload(): void {
    $event = $this->validate([
      'deliveryId' => 'delivery-2',
      'originalDeliveryId' => 'delivery-1',
      'webhookId' => 'webhook-1',
      'storeId' => 'store-1',
      'invoiceId' => 'invoice-1',
      'type' => 'InvoiceSettled',
      'timestamp' => 1234,
      'isRedelivery' => TRUE,
    ]);

    $this->assertSame('delivery-2', $event['delivery_id']);
    $this->assertSame('delivery-1', $event['original_delivery_id']);
    $this->assertSame('webhook-1', $event['webhook_id']);
    $this->assertSame('store-1', $event['store_id']);
    $this->assertSame('invoice-1', $event['invoice_id']);
    $this->assertSame(1234, $event['timestamp']);
  }

  /**
   * Tests rejection when a delivery identifier is absent.
   */
  public function testMissingDeliveryIdIsRejected(): void {
    $this->expectException(\UnexpectedValueException::class);
    $this->validate([
      'webhookId' => 'webhook-1',
      'storeId' => 'store-1',
      'invoiceId' => 'invoice-1',
      'type' => 'InvoiceSettled',
      'timestamp' => 1234,
    ]);
  }

  /**
   * Tests rejection of unrelated signed webhook event types.
   */
  public function testUnexpectedEventTypeIsRejected(): void {
    $this->expectException(\UnexpectedValueException::class);
    $this->validate([
      'deliveryId' => 'delivery-1',
      'webhookId' => 'webhook-1',
      'storeId' => 'store-1',
      'invoiceId' => 'invoice-1',
      'type' => 'PayoutCreated',
      'timestamp' => 1234,
    ]);
  }

  /**
   * Tests that amount binding does not truncate precision after six decimals.
   */
  public function testExactDecimalAmountComparison(): void {
    $reflection = new \ReflectionClass(BtcPayRedirect::class);
    $plugin = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('decimalAmountsEqual');

    $this->assertTrue($method->invoke($plugin, '1.00000010', '1.0000001'));
    $this->assertFalse($method->invoke($plugin, '1.00000010', '1.0000002'));
  }

  /**
   * Invokes the payload validator without constructing the full plugin.
   */
  private function validate(array $payload): array {
    $reflection = new \ReflectionClass(BtcPayRedirect::class);
    $plugin = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('validateWebhookPayload');
    return $method->invoke($plugin, $payload);
  }

}
