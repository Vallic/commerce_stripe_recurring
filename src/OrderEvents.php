<?php

declare(strict_types=1);

namespace Drupal\commerce_stripe_recurring;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription;
use Psr\Log\LoggerInterface;
use Stripe\Invoice;

/**
 * What Stripe says about a subscription after the customer has gone.
 *
 * The return leg only proves a subscription was created. Whether it is
 * *paid* is decided here, and the distinction is not pedantry: a card
 * settles in seconds, a SEPA collection takes days and can fail up to eight
 * weeks later. An integration that marked the order paid on return would be
 * right about cards and wrong about every bank debit. So the order follows
 * the invoice, not the checkout: it is placed and paid on `invoice.paid` and
 * on nothing else.
 *
 * The event arrives already verified: by this project's webhook submodule,
 * or by a site's own endpoint that stores events before handling them. This
 * holds only what an event does to an order.
 */
final readonly class OrderEvents {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private RenewalOrders $renewals,
    private LoggerInterface $logger,
    private OrderLock $orderLock,
    private Conventions $conventions,
  ) {}

  /**
   * Applies one event to its order.
   *
   * @param string $type
   *   The event type.
   * @param object $object
   *   The event's `data.object`.
   * @param int|null $occurred
   *   The event's `created` time, the fallback for when a subscription ended.
   *
   * @return bool
   *   Whether the event changed anything. Never an error: a replay, an event
   *   about nothing of ours and an event this ignores all answer FALSE.
   */
  public function handle(string $type, object $object, ?int $occurred = NULL): bool {
    $dispatch = fn(): bool => match ($type) {
      'checkout.session.completed' => $this->onCheckoutCompleted($object),
      'invoice.paid' => $this->onInvoicePaid($object),
      'invoice.payment_failed' => $this->onInvoiceFailed($object),
      'customer.subscription.deleted' => $this->onSubscriptionEnded($object, $occurred),
      default => FALSE,
    };
    if (!in_array($type, ['checkout.session.completed', 'invoice.paid', 'customer.subscription.deleted'], TRUE)) {
      return $dispatch();
    }

    // Every write below is to the order this event names: serialize on it,
    // and let each branch load it again once the lock is held, so a plan
    // change or another event that saved first is not overwritten.
    $order = $this->orderOf($object);
    if ($order === NULL) {
      return FALSE;
    }

    return $this->orderLock->run($order, static fn(): bool => $dispatch());
  }

  /**
   * A subscription checkout finished, whether or not the customer came back.
   *
   * `onReturn()` records the same two ids and cannot be relied on to: it runs
   * only if the browser follows the redirect. An order with no subscription
   * id reads as one nobody else is billing, so this is the authoritative
   * record and the return leg the convenience. Both write the same fields
   * and either may be first. A `mode=payment` session (a one-off payment) is
   * not an order's subscription and is left to the site.
   */
  private function onCheckoutCompleted(object $session): bool {
    if (($session->mode ?? 'subscription') !== 'subscription') {
      return FALSE;
    }

    $order = $this->orderOf($session);

    if ($order === NULL) {
      return FALSE;
    }

    $subscription = $session->subscription ?? NULL;
    $customer = $session->customer ?? NULL;
    $changed = FALSE;

    if (is_string($subscription) && $subscription !== ''
      && (string) $order->getData(StripeSubscription::SUBSCRIPTION_KEY) === '') {
      $order->setData(StripeSubscription::SUBSCRIPTION_KEY, $subscription);
      $changed = TRUE;
    }

    if (is_string($customer) && $customer !== ''
      && (string) $order->getData(StripeSubscription::CUSTOMER_KEY) === '') {
      $order->setData(StripeSubscription::CUSTOMER_KEY, $customer);
      $changed = TRUE;
    }

    if (!$changed) {
      return FALSE;
    }

    $order->save();

    $this->logger->notice('Order @id was matched to its Stripe subscription from the webhook; the customer did not come back through the return URL.', [
      '@id' => $order->id(),
    ]);

    return TRUE;
  }

  /**
   * An invoice was paid.
   *
   * **Which one this is, is decided by the invoice id recorded against the
   * contract, not by the order's state.** Nothing recorded means this is the
   * first invoice; the same id means Stripe is redelivering; any other id
   * means a genuine later period, which gets an order of its own.
   */
  private function onInvoicePaid(object $invoice): bool {
    $order = $this->orderOf($invoice);

    if ($order === NULL) {
      return FALSE;
    }

    // A subscription the platform cancelled as a duplicate is never placed.
    if ((string) $order->getData($this->conventions->duplicateKey) !== '') {
      return FALSE;
    }

    if (!$invoice instanceof Invoice) {
      // Signed by Stripe and typed as something else. Nothing can be
      // recorded from it, but a draft order still wants placing: the event
      // is authentic, and leaving a paid order in draft is worse than
      // leaving it unreconciled.
      if ($order->getState()->getId() === 'draft') {
        $order->getState()->applyTransitionById('place');
        $order->save();

        $this->logger->warning('An invoice.paid event for order @id carried no invoice; the order was placed but no payment was recorded.', ['@id' => $order->id()]);

        return TRUE;
      }

      $this->logger->warning('An invoice.paid event for order @id carried no invoice.', ['@id' => $order->id()]);

      return FALSE;
    }

    $recorded = (string) $order->getData(RenewalOrders::INVOICE_KEY);

    if ($recorded === (string) $invoice->id) {
      // Stripe telling us again. Recorded once is the whole point.
      return FALSE;
    }

    // The invoice Stripe raises when a trial starts: nothing charged, for a
    // period nobody is paying for. The order is placed if it is not already,
    // but nothing is recorded against it, so the first invoice that does
    // collect money is the one that settles the order.
    if ($recorded === '' && (int) ($invoice->amount_paid ?? 0) === 0 && (int) $order->getData(StripeSubscription::TRIAL_KEY) > 0) {
      if ($order->getState()->getId() === 'draft') {
        $order->getState()->applyTransitionById('place');
        $order->save();
      }

      $this->logger->notice('Order @id started its trial; Stripe raised a zero invoice, which is not the first payment.', ['@id' => $order->id()]);

      return TRUE;
    }

    if ($recorded === '') {
      if ($order->getState()->getId() === 'draft') {
        $order->getState()->applyTransitionById('place');
      }

      // The money, not only the placement.
      $this->renewals->settle($order, $invoice);

      $this->logger->notice('Order @id is paid: Stripe collected its first invoice.', ['@id' => $order->id()]);

      return TRUE;
    }

    return $this->renewals->fromInvoice($order, $invoice) !== NULL;
  }

  /**
   * A collection failed.
   *
   * Recorded and nothing else here. Stripe retries on its own schedule; what
   * the failure means for the customer is the site's dunning, and a
   * second policy in this class would disagree with the first.
   */
  private function onInvoiceFailed(object $invoice): bool {
    $order = $this->orderOf($invoice);

    if ($order === NULL) {
      return FALSE;
    }

    $this->logger->warning('A Stripe collection failed for order @id. Stripe will retry.', ['@id' => $order->id()]);

    return TRUE;
  }

  /**
   * The subscription is over, one way or another.
   *
   * The customer asked to stop; or Stripe exhausted its retries; or the
   * charge was disputed. Stripe sends one event for all of them and puts the
   * difference in `cancellation_details.reason`, which is recorded rather
   * than flattened. Recorded once: the first telling carries the truthful
   * reason.
   */
  private function onSubscriptionEnded(object $subscription, ?int $occurred): bool {
    $order = $this->orderOf($subscription);

    if ($order === NULL) {
      return FALSE;
    }

    if ((string) $order->getData(StripeSubscription::ENDED_KEY) !== '') {
      return FALSE;
    }

    $reason = (string) ($subscription->cancellation_details->reason ?? '');
    // Stripe's time, and the handler's only when Stripe gave none: a test
    // clock moves Stripe's time and not the site's.
    $ended = (int) ($subscription->ended_at ?? $subscription->canceled_at ?? $occurred ?? 0);

    $order->setData(StripeSubscription::ENDED_KEY, $ended > 0 ? $ended : time());
    $order->setData(StripeSubscription::ENDED_REASON_KEY, $reason);
    $order->save();

    $this->logger->notice('The Stripe subscription paying for order @id has ended (@reason).', [
      '@id' => $order->id(),
      '@reason' => $reason === '' ? 'no reason given' : $reason,
    ]);

    return TRUE;
  }

  /**
   * The order a Stripe object belongs to.
   *
   * By the metadata the checkout put there, in the three places it surfaces:
   * a session's `client_reference_id`, a subscription's own metadata, and an
   * invoice's parent (`parent.subscription_details`, `subscription_details`
   * before the 2025 API versions).
   *
   * Where the site names an owner (Conventions), an order that has one is a
   * match only when the metadata's owner, if it names one, is the order's:
   * metadata is a cross-check, and one that disagrees refuses the event
   * rather than moves money onto another tenant's order.
   */
  private function orderOf(object $object): ?OrderInterface {
    $id = $object->client_reference_id
      ?? $object->metadata->order_id
      ?? $object->parent->subscription_details->metadata->order_id
      ?? $object->subscription_details->metadata->order_id
      ?? NULL;

    if ($id === NULL || !ctype_digit((string) $id)) {
      return NULL;
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load((int) $id);

    if (!$order instanceof OrderInterface) {
      return NULL;
    }

    $key = $this->conventions->ownerMetadataKey;
    $claimed = $key === '' ? NULL : (
      $object->metadata->{$key}
      ?? $object->parent->subscription_details->metadata->{$key}
      ?? $object->subscription_details->metadata->{$key}
      ?? NULL
    );

    if (is_scalar($claimed) && (string) $claimed !== '') {
      $owner = $this->conventions->ownerOf($order);
      if ($owner !== '' && $owner !== (string) $claimed) {
        $this->logger->warning('A Stripe event named order @id but another owner; it was ignored.', ['@id' => $order->id()]);
        return NULL;
      }
    }

    return $this->consistent($order, $object) ? $order : NULL;
  }

  /**
   * Whether the object's subscription and customer are the order's own.
   *
   * Once an order records its subscription or customer, an event naming a
   * different one is not about this order, whatever its metadata says: it is
   * refused and logged.
   */
  private function consistent(OrderInterface $order, object $object): bool {
    $subscription = $object->parent->subscription_details->subscription
      ?? $object->subscription
      ?? (str_starts_with((string) ($object->id ?? ''), 'sub_') ? $object->id : NULL);
    $subscription = $this->idOf($subscription);
    $customer = $this->idOf($object->customer ?? NULL);
    $recordedSubscription = (string) $order->getData(StripeSubscription::SUBSCRIPTION_KEY);
    $recordedCustomer = (string) $order->getData(StripeSubscription::CUSTOMER_KEY);

    if (($recordedSubscription !== '' && $subscription !== '' && $subscription !== $recordedSubscription)
      || ($recordedCustomer !== '' && $customer !== '' && $customer !== $recordedCustomer)) {
      $this->logger->warning('A Stripe event for order @id names another subscription or customer than the order records; it was ignored.', ['@id' => $order->id()]);

      return FALSE;
    }

    return TRUE;
  }

  /**
   * The id of a Stripe reference, expanded or not; '' for none.
   */
  private function idOf(mixed $reference): string {
    if (is_string($reference)) {
      return $reference;
    }

    return is_object($reference) && is_string($reference->id ?? NULL) ? $reference->id : '';
  }

}
