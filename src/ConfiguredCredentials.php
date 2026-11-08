<?php

declare(strict_types=1);

namespace Drupal\commerce_stripe_recurring;

use Drupal\commerce_payment\Exception\PaymentGatewayException;

/**
 * The gateway's own keys, as configured on the gateway entity.
 *
 * The mode follows the key's prefix. A secret that is neither a `sk_`/`rk_`
 * test nor live key reads as `off`, which is how a placeholder such as `N/A`
 * is refused rather than sent to Stripe.
 */
final readonly class ConfiguredCredentials implements StripeCredentialsInterface {

  /**
   * Builds the default credentials.
   *
   * @param string|null $apiBase
   *   The `commerce_stripe_recurring.api_base` container parameter.
   */
  public function __construct(private ?string $apiBase = NULL) {}

  /**
   * {@inheritdoc}
   */
  public function secretKey(array $configuration): string {
    $key = trim((string) ($configuration['secret_key'] ?? ''));

    if ($this->mode($configuration) === 'off') {
      throw new PaymentGatewayException('This gateway has no usable Stripe secret key.');
    }

    return $key;
  }

  /**
   * {@inheritdoc}
   */
  public function webhookSecret(array $configuration): string {
    $secret = trim((string) ($configuration['webhook_secret'] ?? ''));

    return str_starts_with($secret, 'whsec_') ? $secret : '';
  }

  /**
   * {@inheritdoc}
   */
  public function mode(array $configuration): string {
    $key = trim((string) ($configuration['secret_key'] ?? ''));

    return match (TRUE) {
      str_starts_with($key, 'sk_test_'), str_starts_with($key, 'rk_test_') => 'test',
      str_starts_with($key, 'sk_live_'), str_starts_with($key, 'rk_live_') => 'live',
      default => 'off',
    };
  }

  /**
   * {@inheritdoc}
   */
  public function apiBase(): ?string {
    return $this->apiBase === '' ? NULL : $this->apiBase;
  }

}
