<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_recurring\Kernel;

use Stripe\StripeClient;

/**
 * A Stripe client that records what it was asked and answers from a script.
 *
 * The gateway reaches Stripe through `StripeClient::getService()`, so that is
 * the one method replaced: everything else about the client — its config, its
 * request options — stays real, and the doubles stand only where the network
 * would be.
 *
 * Recording rather than asserting as it goes. What matters about a revision
 * is the *shape* of the change sent — one update with a new quantity, or a
 * delete followed by an add — and a test that can only assert per call cannot
 * tell those apart.
 */
final class FakeStripe extends StripeClient {

  /**
   * Every call made, in order, as [service, method, arguments].
   *
   * @var array<int, array{string, string, array<int, mixed>}>
   */
  public array $calls = [];

  /**
   * The subscription `retrieve()` answers with.
   */
  public ?object $subscription = NULL;

  /**
   * The subscription a checkout session's `retrieve()` names, if any.
   */
  public ?string $sessionSubscription = NULL;

  /**
   * Prices handed back by `create()`, in order.
   *
   * @var array<int, string>
   */
  public array $priceIds = [];

  /**
   * Which service call, if any, should fail.
   */
  public ?string $failOn = NULL;

  /**
   * The error code Stripe gives with that failure, such as resource_missing.
   */
  public ?string $failCode = NULL;

  /**
   * The customer a finished checkout session names.
   */
  public ?string $sessionCustomer = NULL;

  public function __construct() {
    parent::__construct(['api_key' => 'sk_test_fake']);
  }

  /**
   * {@inheritdoc}
   *
   * @param string $name
   *   The service name.
   *
   * @return mixed
   *   The fake service, or the real one.
   */
  public function getService($name): mixed {
    return match ($name) {
      'subscriptions' => new FakeStripeService($this, 'subscriptions'),
      'prices' => new FakeStripeService($this, 'prices'),
      'checkout' => new FakeStripeService($this, 'checkout'),
      'invoiceItems' => new FakeStripeService($this, 'invoiceItems'),
      'billingPortal' => new FakeStripeService($this, 'billingPortal'),
      default => parent::getService($name),
    };
  }

  /**
   * The arguments of the one call made to a service method.
   *
   * @return array<int, mixed>|null
   *   The arguments, or NULL when it was never called.
   */
  public function call(string $service, string $method): ?array {
    foreach ($this->calls as [$made, $name, $arguments]) {
      if ($made === $service && $name === $method) {
        return $arguments;
      }
    }

    return NULL;
  }

  /**
   * How many times a service method was called.
   */
  public function countOf(string $service, string $method): int {
    return count(array_filter(
      $this->calls,
      static fn (array $call): bool => $call[0] === $service && $call[1] === $method,
    ));
  }

}
