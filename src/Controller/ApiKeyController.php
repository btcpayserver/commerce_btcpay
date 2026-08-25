<?php

namespace Drupal\commerce_btcpay\Controller;

use BTCPayServer\Client\ApiKey;
use Drupal\commerce_btcpay\ApiKeyPermissions;
use Drupal\commerce_btcpay\AuthorizationStateStorage;
use Drupal\commerce_btcpay\ServerUrlPolicy;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts and receives BTCPay API-key authorization.
 */
final class ApiKeyController extends ControllerBase {

  /**
   * Constructs an ApiKeyController object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $paymentEntityTypeManager,
    private readonly AuthorizationStateStorage $authorizationState,
    private readonly ServerUrlPolicy $serverUrlPolicy,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('commerce_btcpay.authorization_state'),
      $container->get('commerce_btcpay.server_url_policy'),
    );
  }

  /**
   * Checks access to the authorization endpoint.
   */
  public function authorizeAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'administer commerce_payment_gateway');
  }

  /**
   * Creates server-side state and returns the BTCPay authorization URL.
   */
  public function beginAuthorization(Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return new JsonResponse(['message' => 'Malformed request.'], 400);
    }

    $gateway_id = is_array($data) && is_string($data['gateway_id'] ?? NULL) ? $data['gateway_id'] : '';
    $server_url = is_array($data) && is_string($data['server_url'] ?? NULL) ? $data['server_url'] : '';
    $gateway = $this->paymentEntityTypeManager->getStorage('commerce_payment_gateway')->load($gateway_id);
    if (!$gateway instanceof PaymentGatewayInterface || $gateway->getPluginId() !== 'btcpay_redirect') {
      return new JsonResponse(['message' => 'Save the BTCPay gateway before authorizing it.'], 400);
    }
    if (!$gateway->access('update', $this->currentUser())) {
      return new JsonResponse(['message' => 'Access denied.'], 403);
    }

    try {
      $server_url = $this->serverUrlPolicy->normalize($server_url);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['message' => $e->getMessage()], 400);
    }

    $state = $this->authorizationState->create(
      (int) $this->currentUser()->id(),
      $server_url,
      $gateway_id,
    );
    $callback_url = Url::fromRoute('commerce_btcpay.api_key_callback', [], [
      'absolute' => TRUE,
      'query' => ['state' => $state],
    ])->toString();
    $authorization_url = ApiKey::getAuthorizeUrl(
      $server_url,
      ApiKeyPermissions::REQUIRED,
      'Drupal Commerce',
      TRUE,
      TRUE,
      $callback_url,
      'drupal-commerce',
    );

    return new JsonResponse(['authorization_url' => $authorization_url]);
  }

  /**
   * Receives the public cross-site POST without changing gateway state.
   */
  public function apiKeyCallback(Request $request): Response {
    $state = $request->query->get('state');
    $api_key = $request->request->get('apiKey');
    if (!is_string($state) || !is_string($api_key) || !$this->authorizationState->attachCandidate($state, $api_key)) {
      return new Response((string) $this->t('Invalid or expired BTCPay authorization.'), 400, [
        'Content-Type' => 'text/plain; charset=UTF-8',
      ]);
    }

    $url = Url::fromRoute('commerce_btcpay.api_key_confirm', [], [
      'query' => ['state' => $state],
    ])->toString();
    return new RedirectResponse($url, 303);
  }

}
