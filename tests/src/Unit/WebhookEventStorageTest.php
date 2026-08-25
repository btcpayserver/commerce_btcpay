<?php

namespace Drupal\Tests\commerce_btcpay\Unit;

use Drupal\commerce_btcpay\WebhookEventStorage;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\MemoryStorage;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests webhook delivery idempotency storage.
 */
#[CoversClass(WebhookEventStorage::class)]
#[Group('commerce_btcpay')]
final class WebhookEventStorageTest extends UnitTestCase {

  /**
   * Tests delivery, redelivery, and timestamp persistence.
   */
  public function testDeliveryIdempotency(): void {
    $memory = new MemoryStorage('commerce_btcpay.webhook_events');
    $factory = $this->createMock(KeyValueFactoryInterface::class);
    $factory->method('get')->willReturn($memory);
    $storage = new WebhookEventStorage($factory);

    $this->assertFalse($storage->isProcessed('gateway', 'delivery-1', 'delivery-1'));
    $storage->markProcessed('gateway', 'invoice', 'delivery-1', 'delivery-1', 100);
    $this->assertTrue($storage->isProcessed('gateway', 'delivery-1', 'delivery-1'));
    $this->assertTrue($storage->isProcessed('gateway', 'delivery-2', 'delivery-1'));
    $this->assertSame(100, $storage->getLastTimestamp('gateway', 'invoice'));

    // An older event can be recorded without regressing the high-water mark.
    $storage->markProcessed('gateway', 'invoice', 'delivery-older', 'delivery-older', 90);
    $this->assertSame(100, $storage->getLastTimestamp('gateway', 'invoice'));
  }

}
