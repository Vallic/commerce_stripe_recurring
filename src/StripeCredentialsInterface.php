<?php

declare(strict_types=1);

namespace Drupal\commerce_stripe_recurring;

/**
 * Where a gateway gets its Stripe keys, and which mode they put it in.
 *
 * The default implementation reads the gateway's own configuration. A site
 * that keeps its keys in the environment decorates
 * `commerce_stripe_recurring.credentials`, so the gateway entity holds no
 * secret at all and the site decides which mode is allowed where.
 */
interface StripeCredentialsInterface {

  /**
   * The secret key the gateway's client is built with.
   *
   * @param array<string, mixed> $configuration
   *   The gateway plugin's configuration.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When there is no usable key: Stripe is off, or the key's mode is
   *   wrong. The gateway refuses to boot, and no request reaches Stripe.
   */
  public function secretKey(array $configuration): string;

  /**
   * The webhook signing secret (`whsec_`), or '' when there is none usable.
   *
   * @param array<string, mixed> $configuration
   *   The gateway plugin's configuration.
   */
  public function webhookSecret(array $configuration): string;

  /**
   * The mode the keys are in: `off`, `test` or `live`.
   *
   * @param array<string, mixed> $configuration
   *   The gateway plugin's configuration.
   */
  public function mode(array $configuration): string;

  /**
   * An API base URL replacing Stripe's, or NULL: stripe-mock in tests only.
   */
  public function apiBase(): ?string;

}
