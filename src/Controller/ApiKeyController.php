<?php

namespace Drupal\commerce_btcpay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for BTCPay API key generation and authorization.
 */
class ApiKeyController extends ControllerBase {

  /**
   * Required permissions for BTCPay API key.
   */
  const REQUIRED_PERMISSIONS = [
    'btcpay.store.canviewinvoices',
    'btcpay.store.cancreateinvoice',
    'btcpay.store.canviewstoresettings',
    'btcpay.store.canmodifyinvoices',
    'btcpay.store.webhooks.canmodifywebhooks',
  ];

  /**
   * Handles the callback from BTCPay Server after authorization.
   */
  public function apiKeyCallback(Request $request) {
    $api_key = $request->request->get('apiKey');
    $permissions = $request->request->all('permissions') ?: [];
    $token = $request->query->get('token');
    $gateway_id = $request->query->get('gateway_id');

    // Validate token format
    if (empty($token) || !preg_match('/^btcpay_\d+_[a-z0-9]+$/', $token)) {
      return [
        '#markup' => $this->t('<h1>Invalid Token</h1><p>Invalid authorization token.</p>'),
      ];
    }

    if (empty($api_key) || empty($permissions)) {
      return [
        '#markup' => $this->t('<h1>Invalid Authorization</h1><p>Invalid authorization response from BTCPay Server.</p>'),
      ];
    }

    if (empty($gateway_id)) {
      return [
        '#markup' => $this->t('<h1>Missing Gateway ID</h1><p>Payment gateway ID is missing. Please try again from the payment gateway configuration page.</p>'),
      ];
    }

    // Load the gateway to get the server URL
    $gateway_storage = \Drupal::entityTypeManager()->getStorage('commerce_payment_gateway');
    $gateway = $gateway_storage->load($gateway_id);
    
    if (!$gateway) {
      return [
        '#markup' => $this->t('<h1>Gateway Not Found</h1><p>Payment gateway "@id" not found.</p>', ['@id' => $gateway_id]),
      ];
    }

    // Get server URL from gateway configuration
    $configuration = $gateway->getPluginConfiguration();
    $server_url = $configuration['server_url'] ?? '';
    
    if (empty($server_url)) {
      return [
        '#markup' => $this->t('<h1>Missing Server URL</h1><p>Server URL not configured in the payment gateway. Please enter it first.</p>'),
      ];
    }

    // Verify the API key works with the server URL
    if (!$this->verifyApiKey($server_url, $api_key)) {
      return [
        '#markup' => $this->t('<h1>Verification Failed</h1><p>Could not verify API key with BTCPay Server.</p>'),
      ];
    }

    // Validate permissions
    $validation = $this->validatePermissions($permissions);
    if (!$validation['valid']) {
      return [
        '#markup' => $this->t('<h1>Invalid Permissions</h1><p>@error</p>', ['@error' => $validation['error']]),
      ];
    }

    // Extract store ID from permissions
    $store_id = $this->extractStoreId($permissions);

    // Update the gateway configuration
    $configuration['api_key'] = $api_key;
    $configuration['store_id'] = $store_id;
    $gateway->setPluginConfiguration($configuration);
    $gateway->save();
    
    $gateway_name = $gateway->label();

    // Show success page
    $settings_url = Url::fromRoute('entity.commerce_payment_gateway.collection')->toString();
    
    return [
      '#markup' => $this->t('
        <div style="max-width: 800px; margin: 50px auto; padding: 20px; font-family: sans-serif;">
          <h1 style="color: #28a745;">✓ API Key Generated and Saved Successfully!</h1>
          <p style="font-size: 16px; line-height: 1.6;">
            Your BTCPay Server has been authorized and the configuration has been saved.
          </p>
          <div style="background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 20px 0;">
            <p style="margin: 5px 0;"><strong>Gateway:</strong> @gateway_name</p>
            <p style="margin: 5px 0;"><strong>Server URL:</strong> @server_url</p>
            <p style="margin: 5px 0;"><strong>Store ID:</strong> @store_id</p>
            <p style="margin: 5px 0;"><strong>API Key:</strong> @api_key_preview</p>
          </div>
          <p style="margin-top: 30px;">
            <a href="@settings_url" style="display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; font-weight: bold;">
              Go to Payment Gateway Settings →
            </a>
          </p>
        </div>
      ', [
        '@gateway_name' => $gateway_name,
        '@server_url' => $server_url,
        '@store_id' => $store_id,
        '@api_key_preview' => substr($api_key, 0, 20) . '...',
        '@settings_url' => $settings_url,
      ]),
    ];
  }

  /**
   * Success page after API key authorization.
   */
  public function apiKeySuccess() {
    $state = \Drupal::state();
    $pending_data = $state->get('commerce_btcpay.pending_config');
    
    if (!$pending_data || empty($pending_data['timestamp'])) {
      return [
        '#markup' => $this->t('<h1>No Pending Authorization</h1><p>No pending authorization found. Please try the authorization process again.</p>'),
      ];
    }
    
    // Check if data is not older than 1 hour
    if ((time() - $pending_data['timestamp']) >= 3600) {
      $state->delete('commerce_btcpay.pending_config');
      return [
        '#markup' => $this->t('<h1>Authorization Expired</h1><p>The authorization has expired. Please try the authorization process again.</p>'),
      ];
    }
    
    $store_id = $pending_data['store_id'];
    $api_key_preview = substr($pending_data['api_key'], 0, 20) . '...';
    $settings_url = Url::fromRoute('entity.commerce_payment_gateway.collection')->toString();
    
    return [
      '#markup' => $this->t('
        <div style="max-width: 800px; margin: 50px auto; padding: 20px; font-family: sans-serif;">
          <h1 style="color: #28a745;">✓ API Key Generated Successfully!</h1>
          <p style="font-size: 16px; line-height: 1.6;">
            Your BTCPay Server has been authorized successfully. The credentials are ready to use.
          </p>
          <div style="background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 20px 0;">
            <p style="margin: 5px 0;"><strong>Server URL:</strong> @server_url</p>
            <p style="margin: 5px 0;"><strong>Store ID:</strong> @store_id</p>
            <p style="margin: 5px 0;"><strong>API Key:</strong> @api_key_preview</p>
          </div>
          <h2>Next Steps:</h2>
          <ol style="font-size: 16px; line-height: 1.8;">
            <li>Go to <a href="@settings_url" style="color: #007bff;">Payment Gateway Settings</a></li>
            <li>Edit your BTCPay payment gateway (or create a new one)</li>
            <li>The Server URL, API Key, and Store ID will be automatically filled</li>
            <li>Click "Save" to complete the setup</li>
          </ol>
          <p style="margin-top: 30px;">
            <a href="@settings_url" style="display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; font-weight: bold;">
              Go to Payment Gateway Settings →
            </a>
          </p>
          <p style="margin-top: 20px; font-size: 14px; color: #666;">
            Note: These credentials will be available for 1 hour. Make sure to save your payment gateway configuration within this time.
          </p>
        </div>
      ', [
        '@server_url' => $pending_data['server_url'],
        '@store_id' => $store_id,
        '@api_key_preview' => $api_key_preview,
        '@settings_url' => $settings_url,
      ]),
    ];
  }

  /**
   * Verifies that the API key works with the server.
   */
  protected function verifyApiKey($server_url, $api_key) {
    try {
      $client = new \BTCPayServer\Client\ApiKey($server_url, $api_key);
      // Try to get current user info to verify the key works
      $client->getCurrent();
      return TRUE;
    }
    catch (\Exception $e) {
      \Drupal::logger('commerce_btcpay')->error('API key verification failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Validates that the permissions are correct.
   */
  protected function validatePermissions(array $permissions) {
    // Extract permission names (without store IDs)
    $permission_names = [];
    foreach ($permissions as $permission) {
      $parts = explode(':', $permission);
      $permission_names[] = $parts[0];
    }

    // Check if all required permissions are present
    $missing = array_diff(self::REQUIRED_PERMISSIONS, $permission_names);
    if (!empty($missing)) {
      return [
        'valid' => FALSE,
        'error' => $this->t('Missing required permissions: @permissions', [
          '@permissions' => implode(', ', $missing),
        ]),
      ];
    }

    // Check if all permissions are for the same store
    $store_ids = [];
    foreach ($permissions as $permission) {
      $parts = explode(':', $permission);
      if (count($parts) === 2) {
        $store_ids[] = $parts[1];
      }
    }

    $unique_stores = array_unique($store_ids);
    if (count($unique_stores) > 1) {
      return [
        'valid' => FALSE,
        'error' => $this->t('Permissions must be for a single store only.'),
      ];
    }

    return ['valid' => TRUE];
  }

  /**
   * Extracts the store ID from permissions.
   */
  protected function extractStoreId(array $permissions) {
    foreach ($permissions as $permission) {
      $parts = explode(':', $permission);
      if (count($parts) === 2) {
        return $parts[1];
      }
    }
    return NULL;
  }

  /**
   * Redirects to payment gateway settings.
   */
  protected function redirectToSettings() {
    return new RedirectResponse(Url::fromRoute('entity.commerce_payment_gateway.collection')->toString());
  }

}
