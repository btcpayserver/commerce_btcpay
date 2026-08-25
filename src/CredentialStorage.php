<?php

namespace Drupal\commerce_btcpay;

use Drupal\commerce_btcpay\Security\SecretEncryptor;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;

/**
 * Stores encrypted BTCPay credentials outside exported configuration.
 */
final class CredentialStorage {

  private const COLLECTION = 'commerce_btcpay.credentials';

  private const ALLOWED_KEYS = [
    'api_key',
    'webhook_secret',
  ];

  /**
   * Constructs a CredentialStorage object.
   */
  public function __construct(
    KeyValueFactoryInterface $keyValueFactory,
    private readonly SecretEncryptor $encryptor,
  ) {
    $this->storage = $keyValueFactory->get(self::COLLECTION);
  }

  /**
   * The credential key/value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  private $storage;

  /**
   * Gets credentials for a payment gateway.
   *
   * @return array
   *   An array containing any stored API key and webhook secret.
   */
  public function get(string $gateway_id): array {
    $payload = $this->storage->get($this->storageKey($gateway_id));
    if (!is_array($payload)) {
      return [];
    }

    $json = $this->encryptor->decrypt($payload);
    $credentials = json_decode($json, TRUE, 8, JSON_THROW_ON_ERROR);
    if (!is_array($credentials)) {
      throw new \UnexpectedValueException('Malformed BTCPay credential data.');
    }

    return array_intersect_key($credentials, array_flip(self::ALLOWED_KEYS));
  }

  /**
   * Updates credentials for a payment gateway.
   *
   * Omitted values are preserved. Empty values remove the corresponding
   * credential.
   */
  public function set(string $gateway_id, #[\SensitiveParameter] array $values): void {
    $credentials = $this->get($gateway_id);
    foreach (self::ALLOWED_KEYS as $key) {
      if (!array_key_exists($key, $values)) {
        continue;
      }
      if ($values[$key] === NULL || $values[$key] === '') {
        unset($credentials[$key]);
      }
      elseif (is_string($values[$key])) {
        $credentials[$key] = $values[$key];
      }
      else {
        throw new \InvalidArgumentException('BTCPay credentials must be strings.');
      }
    }

    if ($credentials === []) {
      $this->delete($gateway_id);
      return;
    }

    $json = json_encode($credentials, JSON_THROW_ON_ERROR);
    $this->storage->set(
      $this->storageKey($gateway_id),
      $this->encryptor->encrypt($json),
    );
  }

  /**
   * Determines whether an API key is stored for a gateway.
   */
  public function hasApiKey(string $gateway_id): bool {
    return !empty($this->get($gateway_id)['api_key']);
  }

  /**
   * Deletes credentials for a gateway.
   */
  public function delete(string $gateway_id): void {
    $this->storage->delete($this->storageKey($gateway_id));
  }

  /**
   * Builds a namespaced storage key.
   */
  private function storageKey(string $gateway_id): string {
    if ($gateway_id === '') {
      throw new \InvalidArgumentException('A gateway ID is required.');
    }
    return hash('sha256', $gateway_id);
  }

}
