<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_recurring\Kernel;

use Stripe\Checkout\Session;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeObject;

/**
 * One service on the fake client — subscriptions, or prices.
 *
 * Every call is recorded on the client and answered from what the test put
 * there. `__call()` rather than named methods so a path that reaches for
 * something nobody anticipated shows up as a recorded call instead of a fatal.
 */
final readonly class FakeStripeService {

  public function __construct(
    private FakeStripe $client,
    private string $name,
  ) {}

  /**
   * A service of a service: `checkout->sessions`.
   */
  public function __get(string $name): self {
    return new self($this->client, $this->name . '.' . $name);
  }

  /**
   * Records the call and answers it.
   *
   * @param string $method
   *   The method called.
   * @param array<int, mixed> $arguments
   *   Its arguments.
   */
  public function __call(string $method, array $arguments): mixed {
    $this->client->calls[] = [$this->name, $method, $arguments];

    if ($this->client->failOn === $this->name . '.' . $method) {
      throw InvalidRequestException::factory('Stripe said no.', 400, NULL, NULL, NULL, $this->client->failCode);
    }

    if ($this->name === 'subscriptions' && $method === 'retrieve') {
      return $this->client->subscription ?? StripeObject::constructFrom(['items' => ['data' => []]]);
    }

    if ($this->name === 'checkout.sessions' && $method === 'retrieve') {
      return StripeObject::constructFrom([
        'subscription' => $this->client->sessionSubscription,
        'customer' => $this->client->sessionCustomer,
        'status' => $this->client->sessionStatus,
        'metadata' => $this->client->sessionMetadata,
      ]);
    }

    if ($this->name === 'checkout.sessions' && $method === 'create') {
      $this->client->sessionMetadata = (array) ($arguments[0]['metadata'] ?? []);

      return Session::constructFrom(['id' => 'cs_test_made', 'url' => 'https://checkout.stripe.test/cs_test_made']);
    }

    if ($this->name === 'billingPortal.sessions' && $method === 'create') {
      return StripeObject::constructFrom(['url' => 'https://billing.stripe.test/p/session']);
    }

    if ($this->name === 'prices' && $method === 'create') {
      $id = array_shift($this->client->priceIds) ?? 'price_made';

      return StripeObject::constructFrom(['id' => $id]);
    }

    return StripeObject::constructFrom([]);
  }

}
