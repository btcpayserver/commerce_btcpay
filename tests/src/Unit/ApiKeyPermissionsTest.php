<?php

namespace Drupal\Tests\commerce_btcpay\Unit;

use Drupal\commerce_btcpay\ApiKeyPermissions;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests authoritative API-key permission validation.
 */
#[CoversClass(ApiKeyPermissions::class)]
#[Group('commerce_btcpay')]
final class ApiKeyPermissionsTest extends UnitTestCase {

  /**
   * Tests a valid least-privilege, single-store permission set.
   */
  public function testValidPermissions(): void {
    $permissions = array_map(
      static fn (string $permission): string => $permission . ':store-1',
      ApiKeyPermissions::REQUIRED,
    );
    $permissions[] = 'btcpay.server.canviewusers';

    $this->assertSame('store-1', ApiKeyPermissions::getStoreId($permissions));
  }

  /**
   * Tests that callback-style unscoped permission claims are rejected.
   */
  public function testUnscopedPermissionsAreRejected(): void {
    $this->expectException(\UnexpectedValueException::class);
    ApiKeyPermissions::getStoreId(ApiKeyPermissions::REQUIRED);
  }

  /**
   * Tests that permissions spanning stores are rejected.
   */
  public function testMultipleStoresAreRejected(): void {
    $permissions = [];
    foreach (ApiKeyPermissions::REQUIRED as $index => $permission) {
      $permissions[] = $permission . ($index === 0 ? ':store-2' : ':store-1');
    }

    $this->expectException(\UnexpectedValueException::class);
    ApiKeyPermissions::getStoreId($permissions);
  }

  /**
   * Tests that every required permission must be present.
   */
  public function testMissingPermissionIsRejected(): void {
    $permissions = array_map(
      static fn (string $permission): string => $permission . ':store-1',
      array_slice(ApiKeyPermissions::REQUIRED, 1),
    );

    $this->expectException(\UnexpectedValueException::class);
    ApiKeyPermissions::getStoreId($permissions);
  }

}
