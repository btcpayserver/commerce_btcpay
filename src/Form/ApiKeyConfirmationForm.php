<?php

namespace Drupal\commerce_btcpay\Form;

use Drupal\commerce_btcpay\ApiKeyManager;
use Drupal\commerce_btcpay\AuthorizationStateStorage;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Confirms API-key authorization in the initiating administrator's session.
 */
final class ApiKeyConfirmationForm extends FormBase {

  /**
   * Constructs an ApiKeyConfirmationForm object.
   */
  public function __construct(
    private readonly AuthorizationStateStorage $authorizationState,
    private readonly ApiKeyManager $apiKeyManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_btcpay.authorization_state'),
      $container->get('commerce_btcpay.api_key_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_btcpay_api_key_confirmation';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $state = $this->getRequest()->query->get('state');
    $authorization = is_string($state)
      ? $this->authorizationState->peekForUser($state, (int) $this->currentUser()->id())
      : NULL;
    if (!$authorization || empty($authorization['candidate_received'])) {
      throw new AccessDeniedHttpException('Invalid, expired, or mismatched BTCPay authorization state.');
    }

    $form['description'] = [
      '#type' => 'item',
      '#title' => $this->t('Complete BTCPay authorization'),
      '#markup' => $this->t('Verify and save the API key for gateway <strong>@gateway</strong> on <strong>@server</strong>.', [
        '@gateway' => $authorization['gateway_id'],
        '@server' => $authorization['server_url'],
      ]),
    ];
    $form['state'] = [
      '#type' => 'hidden',
      '#value' => $state,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Verify and save authorization'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $state = (string) $form_state->getValue('state');
    $authorization = $this->authorizationState->consumeForUser($state, (int) $this->currentUser()->id());
    if (!$authorization) {
      $this->messenger()->addError($this->t('The BTCPay authorization expired or was already used.'));
      $form_state->setRedirect('entity.commerce_payment_gateway.collection');
      return;
    }

    try {
      $result = $this->apiKeyManager->complete($authorization);
      $this->messenger()->addStatus($this->t('BTCPay authorization was verified and saved for @gateway.', [
        '@gateway' => $result['gateway_label'],
      ]));
      if (!$result['webhook_setup']) {
        $this->messenger()->addWarning($this->t('The API key was saved, but the webhook could not be configured. Review the log before enabling payments.'));
      }
      $form_state->setRedirect('entity.commerce_payment_gateway.edit_form', [
        'commerce_payment_gateway' => $result['gateway_id'],
      ]);
    }
    catch (\Throwable) {
      $this->messenger()->addError($this->t('BTCPay authorization could not be verified. No unverified configuration was enabled.'));
      $form_state->setRedirect('entity.commerce_payment_gateway.collection');
    }
  }

}
