<?php

declare(strict_types=1);

namespace Drupal\commerce_stripe_recurring_webhook\Event;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Component\EventDispatcher\Event;
use Stripe\Event as StripeEvent;

/**
 * A verified Stripe event, after this module has applied it to its order.
 *
 * Dispatched for every event the endpoint accepts, whatever its type, so a
 * site can act on the ones this module does not handle (identity, disputes,
 * one-off payments) through the same signed endpoint. A subscriber that acts
 * calls setHandled(), which is what the endpoint reports to Stripe.
 */
final class WebhookEvent extends Event {

  /**
   * The event name.
   */
  public const string NAME = 'commerce_stripe_recurring_webhook.event';

  public function __construct(
    public readonly PaymentGatewayInterface $gateway,
    public readonly StripeEvent $event,
    private bool $handled,
  ) {}

  /**
   * Whether this module or a subscriber acted on the event.
   */
  public function isHandled(): bool {
    return $this->handled;
  }

  /**
   * Records that a subscriber acted on the event.
   */
  public function setHandled(): void {
    $this->handled = TRUE;
  }

}
