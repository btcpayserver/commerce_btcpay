<?php

namespace Drupal\commerce_btcpay;

/**
 * Resolves authoritative BTCPay invoice data to safe Commerce states.
 */
final class InvoiceStatus {

  private const SETTLED_ADDITIONAL_STATUSES = [
    'None',
    'PaidOver',
    'Marked',
  ];

  /**
   * Determines whether checkout may advance for this invoice.
   */
  public static function isCheckoutComplete(array $invoice_data): bool {
    return ($invoice_data['status'] ?? NULL) === 'Settled'
      && in_array((string) ($invoice_data['additionalStatus'] ?? ''), self::SETTLED_ADDITIONAL_STATUSES, TRUE);
  }

  /**
   * Maps current authoritative invoice data to a Commerce payment state.
   */
  public static function paymentState(array $invoice_data): ?string {
    $status = $invoice_data['status'] ?? NULL;
    $additional_status = (string) ($invoice_data['additionalStatus'] ?? '');

    if ($status === 'Settled') {
      return in_array($additional_status, self::SETTLED_ADDITIONAL_STATUSES, TRUE) ? 'completed' : NULL;
    }
    if ($additional_status === 'PaidPartial') {
      return 'authorization';
    }

    return match ($status) {
      'New' => 'new',
      'Processing' => 'authorization',
      'Expired' => 'authorization_expired',
      'Invalid' => 'authorization_voided',
      default => NULL,
    };
  }

  /**
   * Determines whether a requested state update is monotonic and valid.
   */
  public static function canTransition(string $current_state, string $target_state): bool {
    if ($current_state === $target_state) {
      return TRUE;
    }

    $allowed = [
      'new' => ['authorization', 'completed'],
      'authorization' => ['authorization_expired', 'authorization_voided', 'completed'],
    ];
    return in_array($target_state, $allowed[$current_state] ?? [], TRUE);
  }

}
