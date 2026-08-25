<?php

namespace Drupal\commerce_btcpay;

/**
 * Validates the least-privilege permissions returned by BTCPay Server.
 */
final class ApiKeyPermissions {

  /**
   * Permissions required by this module.
   */
  public const REQUIRED = [
    'btcpay.store.canviewinvoices',
    'btcpay.store.cancreateinvoice',
    'btcpay.store.canviewstoresettings',
    'btcpay.store.webhooks.canmodifywebhooks',
  ];

  /**
   * Validates permissions and returns their single store ID.
   *
   * @throws \UnexpectedValueException
   *   Thrown when permissions are missing, unscoped, or span stores.
   */
  public static function getStoreId(array $permissions): string {
    $scopes = [];
    foreach ($permissions as $permission) {
      if (!is_string($permission)) {
        continue;
      }
      [$name, $store_id] = array_pad(explode(':', $permission, 2), 2, NULL);
      if (in_array($name, self::REQUIRED, TRUE) && is_string($store_id) && $store_id !== '') {
        $scopes[$name][] = $store_id;
      }
    }

    foreach (self::REQUIRED as $required_permission) {
      if (empty($scopes[$required_permission])) {
        throw new \UnexpectedValueException(sprintf('Missing required permission: %s', $required_permission));
      }
    }

    $store_ids = array_unique(array_merge(...array_values($scopes)));
    if (count($store_ids) !== 1) {
      throw new \UnexpectedValueException('BTCPay permissions must be scoped to one store.');
    }

    return reset($store_ids);
  }

}
