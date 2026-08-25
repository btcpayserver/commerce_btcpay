<?php

namespace Drupal\Tests\commerce_btcpay\Unit;

use Drupal\commerce_btcpay\AuthorizationStateStorage;
use Drupal\commerce_btcpay\Security\SecretEncryptor;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\PrivateKey;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests one-time administrator-bound authorization state.
 */
#[CoversClass(AuthorizationStateStorage::class)]
#[Group('commerce_btcpay')]
final class AuthorizationStateStorageTest extends UnitTestCase {

  /**
   * Tests identity binding, encrypted attachment, and one-time consumption.
   */
  public function testStateIsBoundAndConsumedOnce(): void {
    $data = [];
    $key_value = $this->createMock(KeyValueStoreExpirableInterface::class);
    $key_value->method('setWithExpireIfNotExists')
      ->willReturnCallback(static function (string $key, mixed $value, int $expire) use (&$data): bool {
        if (array_key_exists($key, $data)) {
          return FALSE;
        }
        $data[$key] = $value;
        return TRUE;
      });
    $key_value->method('setWithExpire')
      ->willReturnCallback(static function (string $key, mixed $value, int $expire) use (&$data): void {
        $data[$key] = $value;
      });
    $key_value->method('get')
      ->willReturnCallback(static function (string $key, mixed $default = NULL) use (&$data): mixed {
        return $data[$key] ?? $default;
      });
    $key_value->method('delete')
      ->willReturnCallback(static function (string $key) use (&$data): void {
        unset($data[$key]);
      });

    $factory = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $factory->method('get')->willReturn($key_value);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000);
    $private_key = $this->createMock(PrivateKey::class);
    $private_key->method('get')->willReturn('private-key');

    $storage = new AuthorizationStateStorage(
      $factory,
      $lock,
      $time,
      new SecretEncryptor($private_key, 'hash-salt'),
    );
    $state = $storage->create(7, 'https://btcpay.example.com', 'gateway');

    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $state);
    $this->assertNull($storage->peekForUser($state, 8));
    $this->assertFalse($storage->peekForUser($state, 7)['candidate_received']);
    $this->assertTrue($storage->attachCandidate($state, 'btcpay-secret-key'));
    $this->assertTrue($storage->peekForUser($state, 7)['candidate_received']);
    $this->assertNull($storage->consumeForUser($state, 8));

    $authorization = $storage->consumeForUser($state, 7);
    $this->assertSame('btcpay-secret-key', $authorization['api_key']);
    $this->assertSame('gateway', $authorization['gateway_id']);
    $this->assertSame('https://btcpay.example.com', $authorization['server_url']);
    $this->assertNull($storage->consumeForUser($state, 7));
  }

}
