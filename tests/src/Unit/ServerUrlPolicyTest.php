<?php

namespace Drupal\Tests\commerce_btcpay\Unit;

use Drupal\commerce_btcpay\ServerUrlPolicy;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests BTCPay Server URL policy enforcement.
 */
#[CoversClass(ServerUrlPolicy::class)]
#[Group('commerce_btcpay')]
final class ServerUrlPolicyTest extends UnitTestCase {

  /**
   * Tests HTTPS URL normalization.
   */
  public function testSecureUrlIsNormalized(): void {
    $policy = new ServerUrlPolicy(FALSE);
    $this->assertSame('https://btcpay.example.com/base', $policy->normalize(' https://btcpay.example.com/base/ '));
  }

  /**
   * Tests rejected URL forms.
   */
  #[DataProvider('rejectedUrlProvider')]
  public function testRejectedUrls(string $url): void {
    $policy = new ServerUrlPolicy(FALSE);
    $this->expectException(\InvalidArgumentException::class);
    $policy->normalize($url);
  }

  /**
   * Provides disallowed URLs.
   */
  public static function rejectedUrlProvider(): array {
    return [
      'http' => ['http://btcpay.example.com'],
      'credentials' => ['https://user:password@btcpay.example.com'],
      'query' => ['https://btcpay.example.com?redirect=elsewhere'],
      'fragment' => ['https://btcpay.example.com#fragment'],
      'non-http scheme' => ['file:///etc/passwd'],
      'not a URL' => ['not-a-url'],
    ];
  }

  /**
   * Tests the explicit local-development HTTP opt-in.
   */
  public function testExplicitHttpOptIn(): void {
    $policy = new ServerUrlPolicy(TRUE);
    $this->assertSame('http://btcpay.local', $policy->normalize('http://btcpay.local/'));
  }

}
