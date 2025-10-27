<?php

namespace Drupal\commerce_btcpay;

use Drupal\commerce_payment\PaymentStorage;

/**
 * Extends PaymentStorage to fix payment gateway loading issue.
 */
class BtcPayPaymentStorage extends PaymentStorage {

  /**
   * {@inheritdoc}
   */
  protected function doCreate(array $values) {
    // Populate the type using the payment gateway.
    if (!isset($values['type']) && !empty($values['payment_gateway'])) {
      $payment_gateway = $values['payment_gateway'];
      
      // Load the payment gateway entity if it's a string ID.
      if (is_string($payment_gateway)) {
        $payment_gateway_storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
        $payment_gateway = $payment_gateway_storage->load($payment_gateway);
        $values['payment_gateway'] = $payment_gateway;
      }
      
      // Get the payment type from the gateway plugin.
      if ($payment_gateway && is_object($payment_gateway)) {
        $plugin = $payment_gateway->getPlugin();
        if ($plugin) {
          $payment_type = $plugin->getPaymentType();
          if ($payment_type) {
            $values['type'] = $payment_type->getPluginId();
          }
          else {
            // Fallback to payment_default for offsite gateways.
            $values['type'] = 'payment_default';
          }
        }
      }
    }
    
    // Ensure type is set.
    if (!isset($values['type'])) {
      $values['type'] = 'payment_default';
    }

    // Call grandparent to skip parent's buggy implementation.
    return \Drupal\Core\Entity\ContentEntityStorageBase::doCreate($values);
  }

}
