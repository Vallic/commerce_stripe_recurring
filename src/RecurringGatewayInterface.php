<?php

declare(strict_types=1);

namespace Drupal\commerce_stripe_recurring;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Stripe\Checkout\Session as CheckoutSession;

/**
 * A gateway that bills an order on a schedule of its own.
 *
 * Declared as an interface so that a site with its own billing clock can ask
 * "is somebody else billing this, and can they be told to stop" without
 * naming a class. An instanceof against a class that is not installed is
 * FALSE rather than a fatal, so the check costs nothing on a site that has
 * never heard of Stripe.
 *
 * Deliberately small. This is not an abstraction over subscriptions; it is
 * the one question a site has to be able to ask a gateway it does not
 * control.
 */
interface RecurringGatewayInterface {

  /**
   * Stops the subscription paying for an order, at a date.
   *
   * A date and not "now", because a cancellation here is served notice: the
   * customer keeps what they have paid for until the period ends, and the
   * period is what they agreed to. Cancelling immediately would be a refund
   * nobody asked for and a service cut nobody was warned about.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The subscription order.
   * @param int $timestamp
   *   When collection should stop.
   *
   * @return bool
   *   TRUE when the remote end has been told, FALSE when there was nothing
   *   to tell — an order that was never handed to this gateway.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the remote end refused. The caller has to know: a cancellation
   *   recorded here and not there goes on collecting money.
   */
  public function cancelSubscriptionAt(OrderInterface $order, int $timestamp): bool;

  /**
   * Stops the remote subscription now, with nothing more collected.
   *
   * For a subscription that was never paid for — a trial that ended with no
   * money — where serving notice would be serving it on nothing, and a date
   * in the future is a date on which the card is tried again.
   *
   * @return bool
   *   TRUE when the remote end has been told, FALSE when there was nothing
   *   to tell.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the remote end refused.
   */
  public function cancelSubscriptionNow(OrderInterface $order): bool;

  /**
   * What identifies the card or account paying for this subscription.
   *
   * The same for the same card however many customers it is entered under,
   * which is what lets a free trial be refused to a card that already had
   * one. Prefixed with the kind of instrument, so a card and a bank account
   * can never collide.
   *
   * @return string|null
   *   The fingerprint, or NULL when there is no payment method yet — or
   *   nothing to ask.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the remote end could not be asked.
   */
  public function paymentFingerprint(OrderInterface $order): ?string;

  /**
   * Ends the subscription's trial now, so the first period is charged now.
   *
   * @return bool
   *   TRUE when the remote end has been told, FALSE when there was nothing
   *   to tell.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the remote end refused.
   */
  public function endTrialNow(OrderInterface $order): bool;

  /**
   * Adds a one-off charge to the subscription's next invoice.
   *
   * For something used during the period and billed in arrears, such as
   * metered time, which is not a line of the subscription and must not
   * become one: a line would bill it again every period.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The subscription order.
   * @param \Drupal\commerce_price\Price $amount
   *   What to charge.
   * @param string $description
   *   What the invoice says it is for.
   * @param string $idempotencyKey
   *   The same key for the same charge, so a retry never charges twice.
   *
   * @return bool
   *   TRUE when the remote end has it, FALSE when the order has no
   *   subscription to put it on.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the remote end refused.
   */
  public function addToNextInvoice(OrderInterface $order, Price $amount, string $description, string $idempotencyKey): bool;

  /**
   * Makes the remote subscription bill what the order now says.
   *
   * A subscription is agreed once and changed constantly after that: a
   * machine is resized, a disk is bought, an environment is added. Each of
   * those changes the order, and a gateway that was told the total once goes
   * on collecting the old one — which is the failure that does not look like
   * one, because money keeps arriving.
   *
   * What happens to the difference is the gateway's business. Stripe prorates:
   * the customer is credited for the part of the period they are giving up and
   * charged for the part they are taking, on the next invoice.
   *
   * Lines the order and the gateway already agree on are left alone. A
   * revision that replaced every line would credit and re-charge the whole
   * subscription each time anything moved, which is both alarming on an
   * invoice and, with a prorating gateway, occasionally expensive.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The subscription order, as it now stands.
   * @param string $proration
   *   One of `create_prorations` (the difference on the next invoice),
   *   `always_invoice` (an upgrade: invoiced and collected now, and refused
   *   as a whole when the payment does not complete) or `none` (the new
   *   amounts from the next renewal, nothing prorated).
   *
   * @return bool
   *   TRUE when the remote end was changed, FALSE when there was nothing to
   *   change — an order no gateway is billing, or one that already matches.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the remote end refused. Never swallowed: a change recorded here
   *   and refused there is an invoice that will not match the service.
   */
  public function reviseSubscription(OrderInterface $order, string $proration = 'create_prorations'): bool;

  /**
   * Whether Stripe's subscription already bills exactly the order's lines.
   *
   * Asked to decide a change whose outcome is unknown: a subscription that
   * has the new lines took the change, one that has the old lines did not.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the order has no subscription, or Stripe could not be asked.
   */
  public function revisionApplied(OrderInterface $order): bool;

  /**
   * Expires the order's Checkout session so it can no longer be paid.
   *
   * An already expired or unknown session is not an error.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When Stripe refuses.
   */
  public function expireCheckoutSession(OrderInterface $order): void;

  /**
   * Takes back a cancellation that has not taken effect yet.
   *
   * @return bool
   *   TRUE when the remote end was told, FALSE when there was nothing to tell.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the remote end refused.
   */
  public function resumeSubscription(OrderInterface $order): bool;

  /**
   * The order's Checkout session while it can still be paid, or NULL.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When Stripe could not be asked.
   */
  public function findOpenCheckoutSession(OrderInterface $order): ?CheckoutSession;

  /**
   * Refunds the first invoice of the order's subscription, if it was paid.
   *
   * @return string|null
   *   The refund id; the empty string when nothing was or will ever be paid
   *   (the subscription is gone, the invoice void or free); NULL when nothing
   *   has been paid yet and the answer may change.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When Stripe refuses.
   */
  public function refundFirstInvoice(OrderInterface $order): ?string;

  /**
   * Where a customer manages the payment details this gateway holds.
   *
   * The second question a site has to be able to ask a gateway it does
   * not control, and for the same reason as the first: the answer is not
   * ours to reimplement. A card lives at the gateway, is changed at the
   * gateway, and every copy of it kept here is a copy that can be wrong, be
   * leaked, or need a PCI argument made for it.
   *
   * So this returns somewhere to send them rather than anything to store.
   *
   * @param string $customer
   *   The gateway's own id for this customer.
   * @param string $returnUrl
   *   Where the gateway should send them back to when they are done.
   *
   * @return string|null
   *   A URL, or NULL for a gateway with nowhere to send anybody.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the gateway refused to open one.
   */
  public function billingPortalUrl(string $customer, string $returnUrl): ?string;

  /**
   * Opens the Checkout session that starts a subscription.
   *
   * That is `mode=subscription`.
   *
   * Priced inline from the order's adjusted (tax-inclusive) unit prices, for
   * the whole period where the site prices its orders for one month
   * (Conventions), with `metadata{order_id}`, and the owner where the site
   * names one, on the session and on the subscription: a cross-check when
   * an event comes back, never what decides whose the order is.
   *
   * The metadata also says what the checkout was opened for, the lines and
   * the trial as one value: a checkout places a draft order only while the
   * order still holds that. The checkout the order was sent to before is
   * expired, and the order records the new session's id.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The subscription order.
   * @param string $returnUrl
   *   Where Stripe sends somebody who finished.
   * @param string $cancelUrl
   *   Where Stripe sends somebody who did not.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When Stripe refuses, or the mode refuses to boot the gateway.
   */
  public function createCheckoutSession(
    OrderInterface $order,
    string $returnUrl,
    string $cancelUrl,
  ): CheckoutSession;

  /**
   * Opens the Checkout session of a one-off payment (`mode=payment`).
   *
   * The session carries the same metadata as a subscription's, what it was
   * opened for included. The checkout the order was sent to before is
   * expired, and the order records the new session's id. Nothing should be
   * granted until the session's `payment_status` is `paid` or its
   * asynchronous payment succeeds, and only while the order still holds what
   * the session was opened for: that is the site's to handle, from
   * `checkout.session.completed` and
   * `checkout.session.async_payment_succeeded`.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When Stripe refuses, or the mode refuses to boot the gateway.
   */
  public function createPaymentCheckoutSession(OrderInterface $order, string $returnUrl, string $cancelUrl): CheckoutSession;

  /**
   * Starts a subscription billed on invoice terms (`send_invoice`).
   *
   * Terms a site grants by hand: no Checkout, no card. Stripe emails the
   * invoice and collects `$daysUntilDue` days after it.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The subscription order.
   * @param string $customer
   *   The Stripe customer, which has to exist already.
   * @param int $daysUntilDue
   *   At least 1.
   *
   * @return string
   *   The subscription id, also recorded on the order.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When Stripe refuses.
   */
  public function createInvoicedSubscription(OrderInterface $order, string $customer, int $daysUntilDue): string;

  /**
   * Refunds a payment, whole or in part.
   *
   * @param string $paymentIntent
   *   The payment intent (`pi_`) to refund.
   * @param int|null $amountMinor
   *   Minor units, or NULL for everything left.
   * @param string $reason
   *   One of `requested_by_customer`, `duplicate` or `fraudulent`.
   *
   * @return string
   *   The refund id (`re_`).
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When Stripe refuses.
   */
  public function refundPayment(string $paymentIntent, ?int $amountMinor = NULL, string $reason = 'requested_by_customer'): string;

  /**
   * Cancels every live subscription of a customer now, collecting nothing.
   *
   * For closing a customer's account: idempotent, and a customer
   * with none, or one Stripe does not know (`resource_missing`), is a success.
   *
   * @return int
   *   How many subscriptions were cancelled by this call.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When Stripe refused: the account must then stay open.
   */
  public function cancelCustomerSubscriptions(string $customer): int;

  /**
   * Makes a Stripe customer, on a test clock when one is given.
   *
   * The clock is accepted in test mode only; the customer's `test_clock` is
   * what a site records to advance the customer's time in tests.
   *
   * @param string $email
   *   The billing address.
   * @param array<string, string> $metadata
   *   Metadata, such as the owner's identifier.
   * @param string|null $testClock
   *   A test clock id (`clock_`), test mode only.
   *
   * @return string
   *   The customer id.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When Stripe refuses, or a clock is given outside test mode.
   */
  public function createCustomer(string $email, array $metadata = [], ?string $testClock = NULL): string;

  /**
   * Makes a test clock frozen at an instant (test mode only).
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When Stripe refuses, or the mode is not test.
   */
  public function createTestClock(int $frozenTime, string $name = ''): string;

  /**
   * Reads a test clock's `frozen_time` (test mode only).
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When Stripe refuses, or the mode is not test.
   */
  public function testClockFrozenTime(string $testClock): int;

  /**
   * Lists the events Stripe holds since an instant, oldest first.
   *
   * For replaying events a site missed, after restoring a backup for
   * instance: each goes to the same handler as a webhook.
   *
   * @return iterable<\Stripe\Event>
   *   The events, paged through the API.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When Stripe refuses.
   */
  public function eventsSince(int $since): iterable;

}
