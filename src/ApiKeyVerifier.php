<?php

namespace Drupal\commerce_btcpay;

use BTCPayServer\Client\ApiKey;
use BTCPayServer\Client\Store;

/**
 * Verifies an API key against authoritative BTCPay Server data.
 */
final class ApiKeyVerifier {

  /**
   * Constructs an ApiKeyVerifier object.
   */
  public function __construct(private readonly ServerUrlPolicy $serverUrlPolicy) {}

  /**
   * Verifies a key and returns its single authorized store ID.
   *
   * @param string $server_url
   *   The BTCPay Server base URL.
   * @param string $api_key
   *   The candidate API key.
   * @param string|null $expected_store_id
   *   An optional store ID that must match the key permissions.
   */
  public function verify(
    string $server_url,
    #[\SensitiveParameter] string $api_key,
    ?string $expected_store_id = NULL,
  ): string {
    $server_url = $this->serverUrlPolicy->normalize($server_url);
    $result = (new ApiKey($server_url, $api_key))->getCurrent();
    $store_id = ApiKeyPermissions::getStoreId($result->getPermissions());

    if ($expected_store_id !== NULL && !hash_equals($expected_store_id, $store_id)) {
      throw new \UnexpectedValueException('The API key is not scoped to the configured store.');
    }

    // This confirms the selected store exists and is accessible with the key.
    (new Store($server_url, $api_key))->getStore($store_id);
    return $store_id;
  }

}
