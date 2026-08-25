<?php

namespace Drupal\Tests\commerce_btcpay\Unit;

use Drupal\commerce_btcpay\Security\SecretEncryptor;
use Drupal\Core\PrivateKey;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests authenticated encryption for protected credentials.
 */
#[CoversClass(SecretEncryptor::class)]
#[Group('commerce_btcpay')]
final class SecretEncryptorTest extends UnitTestCase {

  /**
   * Tests randomized authenticated encryption and decryption.
   */
  public function testRoundTripAndRandomization(): void {
    $private_key = $this->createMock(PrivateKey::class);
    $private_key->method('get')->willReturn('private-key');
    $encryptor = new SecretEncryptor($private_key, 'hash-salt');

    $first = $encryptor->encrypt('sensitive-value');
    $second = $encryptor->encrypt('sensitive-value');
    $this->assertNotSame($first['ciphertext'], $second['ciphertext']);
    $this->assertSame('sensitive-value', $encryptor->decrypt($first));
    $this->assertSame('sensitive-value', $encryptor->decrypt($second));
  }

  /**
   * Tests that authenticated ciphertext tampering is rejected.
   */
  public function testTamperingIsRejected(): void {
    $private_key = $this->createMock(PrivateKey::class);
    $private_key->method('get')->willReturn('private-key');
    $encryptor = new SecretEncryptor($private_key, 'hash-salt');
    $payload = $encryptor->encrypt('sensitive-value');
    $payload['ciphertext'] = base64_encode('tampered');

    $this->expectException(\UnexpectedValueException::class);
    $encryptor->decrypt($payload);
  }

}
