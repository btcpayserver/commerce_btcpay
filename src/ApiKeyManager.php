<?php

namespace Drupal\commerce_btcpay;

use Drupal\commerce_btcpay\Plugin\Commerce\PaymentGateway\BtcPayInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Completes verified BTCPay API-key authorization for an existing gateway.
 */
final class ApiKeyManager {

  /**
   * Constructs an ApiKeyManager object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CredentialStorage $credentialStorage,
    private readonly ApiKeyVerifier $apiKeyVerifier,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Verifies and stores an authorization candidate.
   *
   * @return array
   *   Non-secret details for the confirmation message.
   */
  public function complete(#[\SensitiveParameter] array $authorization): array {
    $gateway_id = (string) ($authorization['gateway_id'] ?? '');
    $server_url = (string) ($authorization['server_url'] ?? '');
    $api_key = $authorization['api_key'] ?? NULL;
    if ($gateway_id === '' || $server_url === '' || !is_string($api_key) || $api_key === '') {
      throw new \InvalidArgumentException('Incomplete BTCPay authorization state.');
    }

    $storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    $gateway = $storage->load($gateway_id);
    if (!$gateway instanceof PaymentGatewayInterface || $gateway->getPluginId() !== 'btcpay_redirect') {
      throw new \UnexpectedValueException('The BTCPay payment gateway no longer exists.');
    }

    try {
      // Permissions and the store are fetched from BTCPay, never from callback
      // parameters.
      $store_id = $this->apiKeyVerifier->verify($server_url, $api_key);
      $this->credentialStorage->set($gateway_id, ['api_key' => $api_key]);

      $configuration = $gateway->getPluginConfiguration();
      $configuration['server_url'] = $server_url;
      $configuration['store_id'] = $store_id;
      unset($configuration['api_key'], $configuration['webhook_secret']);
      $gateway->setPluginConfiguration($configuration);
      $gateway->save();

      // Reload so the plugin receives the newly persisted parent entity and
      // configuration before setting up its webhook.
      $storage->resetCache([$gateway_id]);
      $gateway = $storage->load($gateway_id);
      if (!$gateway instanceof PaymentGatewayInterface) {
        throw new \UnexpectedValueException('The BTCPay payment gateway could not be reloaded.');
      }
      $plugin = $gateway->getPlugin();
      if (!$plugin instanceof BtcPayInterface) {
        throw new \UnexpectedValueException('Unexpected BTCPay gateway plugin.');
      }

      $webhook_setup = $plugin->setupWebhook($gateway_id);
      $configuration = $plugin->getConfiguration();
      unset($configuration['api_key'], $configuration['webhook_secret']);
      $gateway->setPluginConfiguration($configuration);
      $gateway->save();

      return [
        'gateway_id' => $gateway_id,
        'gateway_label' => $gateway->label(),
        'server_url' => $server_url,
        'store_id' => $store_id,
        'webhook_setup' => $webhook_setup,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('BTCPay authorization failed for gateway @gateway (@type).', [
        '@gateway' => $gateway_id,
        '@type' => get_class($e),
      ]);
      throw new \RuntimeException('BTCPay Server rejected the API key or its permissions.', 0, $e);
    }
  }

}
