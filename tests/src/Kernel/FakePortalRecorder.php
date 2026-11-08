<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_recurring\Kernel;

/**
 * One portal service of FakePortalStripe.
 */
final readonly class FakePortalRecorder {

  public function __construct(
    private FakePortalStripe $client,
    private string $kind,
    private string $id,
  ) {}

  /**
   * Records the call and answers as Stripe would.
   *
   * @param array<string, mixed> $parameters
   *   What was sent.
   */
  public function create(array $parameters): object {
    $this->client->calls[] = [$this->kind, $parameters];
    return (object) ['id' => $this->id, 'url' => 'https://billing.stripe.com/session/test'];
  }

}
