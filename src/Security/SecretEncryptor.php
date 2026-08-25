<?php

namespace Drupal\commerce_btcpay\Security;

use Drupal\Core\PrivateKey;
use Drupal\Core\Site\Settings;

/**
 * Encrypts module secrets with a site-specific key.
 */
final class SecretEncryptor {

  private const CIPHER = 'aes-256-gcm';

  /**
   * Constructs a SecretEncryptor object.
   *
   * @param \Drupal\Core\PrivateKey $privateKey
   *   The Drupal private key service.
   * @param string|null $hashSalt
   *   An optional hash salt override, used by tests.
   */
  public function __construct(
    private readonly PrivateKey $privateKey,
    private readonly ?string $hashSalt = NULL,
  ) {}

  /**
   * Encrypts a value.
   *
   * @param string $plaintext
   *   The plaintext value.
   *
   * @return array
   *   The versioned encrypted payload.
   */
  public function encrypt(#[\SensitiveParameter] string $plaintext): array {
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
      $plaintext,
      self::CIPHER,
      $this->getEncryptionKey(),
      OPENSSL_RAW_DATA,
      $iv,
      $tag,
      '',
      16,
    );

    if ($ciphertext === FALSE) {
      throw new \RuntimeException('Unable to encrypt the BTCPay secret.');
    }

    return [
      'version' => 1,
      'iv' => base64_encode($iv),
      'tag' => base64_encode($tag),
      'ciphertext' => base64_encode($ciphertext),
    ];
  }

  /**
   * Decrypts a value.
   *
   * @param array $payload
   *   The versioned encrypted payload.
   *
   * @return string
   *   The decrypted plaintext.
   */
  public function decrypt(#[\SensitiveParameter] array $payload): string {
    if (($payload['version'] ?? NULL) !== 1) {
      throw new \UnexpectedValueException('Unsupported BTCPay secret format.');
    }

    $iv = $this->decode($payload, 'iv');
    $tag = $this->decode($payload, 'tag');
    $ciphertext = $this->decode($payload, 'ciphertext');
    $plaintext = openssl_decrypt(
      $ciphertext,
      self::CIPHER,
      $this->getEncryptionKey(),
      OPENSSL_RAW_DATA,
      $iv,
      $tag,
    );

    if ($plaintext === FALSE) {
      throw new \UnexpectedValueException('Unable to decrypt the BTCPay secret.');
    }

    return $plaintext;
  }

  /**
   * Derives an encryption key from secrets stored outside exported config.
   */
  private function getEncryptionKey(): string {
    $hash_salt = $this->hashSalt ?? Settings::getHashSalt();
    return hash('sha256', $hash_salt . "\0" . $this->privateKey->get(), TRUE);
  }

  /**
   * Decodes a required base64 payload member.
   */
  private function decode(array $payload, string $key): string {
    if (!isset($payload[$key]) || !is_string($payload[$key])) {
      throw new \UnexpectedValueException('Malformed BTCPay secret payload.');
    }

    $decoded = base64_decode($payload[$key], TRUE);
    if ($decoded === FALSE) {
      throw new \UnexpectedValueException('Malformed BTCPay secret payload.');
    }
    return $decoded;
  }

}
