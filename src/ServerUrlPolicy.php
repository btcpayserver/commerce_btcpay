<?php

namespace Drupal\commerce_btcpay;

use Drupal\Core\Site\Settings;

/**
 * Validates and normalizes BTCPay Server base URLs.
 */
final class ServerUrlPolicy {

  /**
   * Constructs a ServerUrlPolicy object.
   *
   * @param bool|null $allowInsecureHttp
   *   An optional policy override, used by tests.
   */
  public function __construct(private readonly ?bool $allowInsecureHttp = NULL) {}

  /**
   * Validates and normalizes a BTCPay Server URL.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the URL is not allowed.
   */
  public function normalize(string $server_url): string {
    $server_url = trim($server_url);
    if ($server_url === '' || filter_var($server_url, FILTER_VALIDATE_URL) === FALSE) {
      throw new \InvalidArgumentException('Enter a valid BTCPay Server URL.');
    }

    $parts = parse_url($server_url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
      throw new \InvalidArgumentException('Enter a valid BTCPay Server URL.');
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
      throw new \InvalidArgumentException('The BTCPay Server URL cannot contain credentials, a query, or a fragment.');
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'https') {
      if ($scheme !== 'http' || !$this->allowsInsecureHttp()) {
        throw new \InvalidArgumentException('The BTCPay Server URL must use HTTPS.');
      }
    }

    return rtrim($server_url, '/');
  }

  /**
   * Determines whether this explicitly local policy permits plain HTTP.
   */
  public function allowsInsecureHttp(): bool {
    return $this->allowInsecureHttp ?? (bool) Settings::get('commerce_btcpay_allow_insecure_http', FALSE);
  }

}
