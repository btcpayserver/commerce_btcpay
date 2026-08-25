<?php

namespace Drupal\commerce_btcpay;

use Drupal\commerce_btcpay\Security\SecretEncryptor;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Stores one-time API authorization state and encrypted callback candidates.
 */
final class AuthorizationStateStorage {

  private const COLLECTION = 'commerce_btcpay.authorization_state';

  private const LIFETIME = 900;

  /**
   * Constructs an AuthorizationStateStorage object.
   */
  public function __construct(
    KeyValueExpirableFactoryInterface $keyValueFactory,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly SecretEncryptor $encryptor,
  ) {
    $this->storage = $keyValueFactory->get(self::COLLECTION);
  }

  /**
   * The expirable state key/value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   */
  private $storage;

  /**
   * Creates state bound to an administrator, server, and existing gateway.
   */
  public function create(int $uid, string $server_url, string $gateway_id): string {
    if ($uid <= 0 || $gateway_id === '') {
      throw new \InvalidArgumentException('Authorization state requires an administrator and gateway.');
    }

    do {
      $state = Crypt::randomBytesBase64(32);
    } while (!$this->storage->setWithExpireIfNotExists($this->key($state), [
      'uid' => $uid,
      'server_url' => $server_url,
      'gateway_id' => $gateway_id,
      'created' => $this->time->getRequestTime(),
    ], self::LIFETIME));

    return $state;
  }

  /**
   * Attaches an encrypted API key candidate received by the public callback.
   */
  public function attachCandidate(string $state, #[\SensitiveParameter] string $api_key): bool {
    if (!$this->isValidState($state) || $api_key === '' || strlen($api_key) > 2048) {
      return FALSE;
    }

    $lock_name = $this->lockName($state);
    if (!$this->lock->acquire($lock_name, 10.0)) {
      return FALSE;
    }

    try {
      $record = $this->storage->get($this->key($state));
      if (!is_array($record)) {
        return FALSE;
      }
      if (isset($record['candidate'])) {
        // The first callback wins. Repeated browser submissions remain safe.
        return TRUE;
      }

      $remaining = self::LIFETIME - ($this->time->getRequestTime() - (int) ($record['created'] ?? 0));
      if ($remaining <= 0) {
        $this->storage->delete($this->key($state));
        return FALSE;
      }

      $record['candidate'] = $this->encryptor->encrypt($api_key);
      $this->storage->setWithExpire($this->key($state), $record, $remaining);
      return TRUE;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Gets non-secret state for the initiating administrator.
   */
  public function peekForUser(string $state, int $uid): ?array {
    if (!$this->isValidState($state)) {
      return NULL;
    }
    $record = $this->storage->get($this->key($state));
    if (!is_array($record) || (int) ($record['uid'] ?? 0) !== $uid) {
      return NULL;
    }

    $candidate_received = is_array($record['candidate'] ?? NULL);
    unset($record['candidate']);
    $record['candidate_received'] = $candidate_received;
    return $record;
  }

  /**
   * Atomically consumes state for the initiating administrator.
   *
   * @return array|null
   *   The state with its decrypted API key, or NULL when invalid.
   */
  public function consumeForUser(string $state, int $uid): ?array {
    if (!$this->isValidState($state)) {
      return NULL;
    }

    $lock_name = $this->lockName($state);
    if (!$this->lock->acquire($lock_name, 10.0)) {
      return NULL;
    }

    try {
      $record = $this->storage->get($this->key($state));
      if (!is_array($record) || (int) ($record['uid'] ?? 0) !== $uid || !is_array($record['candidate'] ?? NULL)) {
        return NULL;
      }

      // Delete before decrypting or making remote calls: state is one-time.
      $this->storage->delete($this->key($state));
      $record['api_key'] = $this->encryptor->decrypt($record['candidate']);
      unset($record['candidate']);
      return $record;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Determines whether a value has the expected random-state encoding.
   */
  private function isValidState(string $state): bool {
    return preg_match('/^[A-Za-z0-9_-]{43}$/D', $state) === 1;
  }

  /**
   * Builds the opaque key/value storage key.
   */
  private function key(string $state): string {
    return hash('sha256', $state);
  }

  /**
   * Builds the lock name for a state value.
   */
  private function lockName(string $state): string {
    return 'commerce_btcpay.authorization.' . hash('sha256', $state);
  }

}
