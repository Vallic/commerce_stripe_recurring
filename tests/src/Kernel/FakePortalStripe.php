<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_recurring\Kernel;

use Stripe\StripeClient;

/**
 * A Stripe client whose billing portal records what it is asked.
 */
final class FakePortalStripe extends StripeClient {

  /**
   * The calls, as [kind, parameters].
   *
   * @var list<array{string, array<string, mixed>}>
   */
  public array $calls = [];

  public function __construct() {
    parent::__construct(['api_key' => 'sk_test_fake']);
  }

  /**
   * {@inheritdoc}
   *
   * @param string $name
   *   The service name.
   */
  public function getService($name): mixed {
    return (object) [
      'configurations' => new FakePortalRecorder($this, 'configuration', 'bpc_test'),
      'sessions' => new FakePortalRecorder($this, 'session', 'bps_test'),
    ];
  }

}
