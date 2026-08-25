<?php

namespace Drupal\commerce_btcpay;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;

/**
 * Persists webhook delivery idempotency and ordering information.
 */
final class WebhookEventStorage {

  private const COLLECTION = 'commerce_btcpay.webhook_events';

  /**
   * Constructs a WebhookEventStorage object.
   */
  public function __construct(KeyValueFactoryInterface $keyValueFactory) {
    $this->storage = $keyValueFactory->get(self::COLLECTION);
  }

  /**
   * The event key/value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  private $storage;

  /**
   * Checks both a delivery and its original delivery for prior processing.
   */
  public function isProcessed(string $gateway_id, string $delivery_id, string $original_delivery_id): bool {
    return $this->storage->has($this->deliveryKey($gateway_id, $delivery_id))
      || $this->storage->has($this->deliveryKey($gateway_id, $original_delivery_id));
  }

  /**
   * Gets the latest successfully processed event timestamp for an invoice.
   */
  public function getLastTimestamp(string $gateway_id, string $invoice_id): int {
    return (int) $this->storage->get($this->timestampKey($gateway_id, $invoice_id), 0);
  }

  /**
   * Marks both delivery identifiers processed and advances the timestamp.
   */
  public function markProcessed(
    string $gateway_id,
    string $invoice_id,
    string $delivery_id,
    string $original_delivery_id,
    int $timestamp,
  ): void {
    $data = [
      $this->deliveryKey($gateway_id, $delivery_id) => $timestamp,
      $this->deliveryKey($gateway_id, $original_delivery_id) => $timestamp,
    ];
    $this->storage->setMultiple($data);

    if ($timestamp > $this->getLastTimestamp($gateway_id, $invoice_id)) {
      $this->storage->set($this->timestampKey($gateway_id, $invoice_id), $timestamp);
    }
  }

  /**
   * Deletes all stored webhook processing records.
   */
  public function deleteAll(): void {
    $this->storage->deleteAll();
  }

  /**
   * Builds an opaque delivery key.
   */
  private function deliveryKey(string $gateway_id, string $delivery_id): string {
    return 'delivery:' . hash('sha256', $gateway_id . "\0" . $delivery_id);
  }

  /**
   * Builds an opaque invoice timestamp key.
   */
  private function timestampKey(string $gateway_id, string $invoice_id): string {
    return 'timestamp:' . hash('sha256', $gateway_id . "\0" . $invoice_id);
  }

}
