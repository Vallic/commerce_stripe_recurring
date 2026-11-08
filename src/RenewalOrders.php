<?php

declare(strict_types=1);

namespace Drupal\commerce_stripe_recurring;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\MinorUnitsConverterInterface;
use Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription;
use Psr\Log\LoggerInterface;
use Stripe\Invoice;
use Stripe\Subscription as RemoteSubscription;

/**
 * An order of our own for every period Stripe bills.
 *
 * The subscription order is the contract: it is what somebody agreed to and
 * it is placed once. Every period after the first is a separate charge with
 * its own date, its own amount and its own document, and without an order
 * for it the only record of month two is in somebody else's dashboard.
 *
 * That is a bookkeeping problem before it is a product one. An invoice has
 * to be numbered, dated, and handed to an accounting system; "log in to
 * Stripe and look" is not a ledger, and a customer asking what they paid in
 * March is asking us, not Stripe.
 *
 * So each renewal invoice becomes an order here, mirroring what Stripe
 * actually charged rather than re-deriving it from the catalogue. Stripe is
 * the system of record for the money; this is the record of the money.
 */
final readonly class RenewalOrders {

  /**
   * Order data key holding the Stripe invoice an order mirrors.
   */
  public const string INVOICE_KEY = 'stripe_invoice_id';

  /**
   * Order data key naming the order this one renews.
   */
  public const string RENEWS_KEY = 'stripe_renews_order';

  /**
   * Order data key holding Stripe's hosted copy of the invoice.
   */
  public const string INVOICE_URL_KEY = 'stripe_invoice_url';

  /**
   * Order data keys holding the period a renewal covers, as timestamps.
   */
  public const string PERIOD_START_KEY = 'stripe_period_start';
  public const string PERIOD_END_KEY = 'stripe_period_end';

  /**
   * The fields a renewal gets on its own terms rather than by inheritance.
   *
   * Commerce's own, plus the identity ones. Written out rather than derived
   * from the base field definitions, because on many sites the interesting
   * fields — a team, a cost centre — are *also* base fields,
   * added by hook, so "is it a base field" does not draw the line anywhere
   * useful. This does, and a Commerce release that adds a field to the order
   * makes it show up here as something to think about rather than silently
   * copying.
   *
   * @var array<int, string>
   */
  private const array COMMERCE_FIELDS = [
    'order_id', 'uuid', 'type', 'langcode', 'default_langcode',
    'order_number', 'version', 'store_id', 'uid', 'mail', 'ip_address',
    'billing_profile', 'shipping_profile', 'order_items', 'adjustments',
    'total_price', 'total_paid', 'balance', 'state', 'data', 'locked',
    'created', 'changed', 'placed', 'completed', 'cart', 'checkout_step',
    'checkout_flow', 'payment_gateway', 'payment_method', 'coupons',
    'refresh_state',
  ];

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private MinorUnitsConverterInterface $minorUnits,
    private LoggerInterface $logger,
    private Conventions $conventions,
  ) {}

  /**
   * The order recording one renewal, created if it does not exist yet.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $subscription
   *   The order the subscription was bought on.
   * @param \Stripe\Invoice $invoice
   *   The invoice Stripe collected.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The renewal order, or NULL when the invoice has nothing on it.
   */
  public function fromInvoice(OrderInterface $subscription, Invoice $invoice): ?OrderInterface {
    $reference = $this->referenceFor($invoice);
    $existing = $this->existing($reference);

    if ($existing !== NULL) {
      // Stripe delivers an event more than once when it is unsure we heard
      // it, and a retry that billed a customer a second time on paper would
      // be a worse bug than the missing order this fixes. The payment is
      // repeated too, idempotently: a crash between the order's two saves
      // and its payment is repaired by the retry.
      $this->recordPayment($existing, $invoice);
      $this->recordIntent($existing, $invoice);
      $existing->save();

      return $existing;
    }

    $items = $this->itemsFrom($invoice, $subscription);

    if ($items === []) {
      $this->logger->warning('Stripe invoice @invoice for order @order has nothing on it; no renewal order was created.', [
        '@invoice' => $reference,
        '@order' => $subscription->id(),
      ]);

      return NULL;
    }

    $order = Order::create([
      'type' => $subscription->bundle(),
      'store_id' => $subscription->getStoreId(),
      'uid' => $subscription->getCustomerId(),
      'mail' => $subscription->getEmail(),
      'order_number' => $reference,
      'billing_profile' => $this->copyOfBillingProfile($subscription),
      'payment_gateway' => $subscription->get('payment_gateway')->target_id,
    ]);

    // Whatever else the order carries that is not Commerce's — a team, an
    // account. Copied by name rather than by a list written here, so a
    // field added to the order type travels to its renewals without this
    // module being taught about it.
    $this->carryOver($subscription, $order);

    // Never re-priced. Every line on this order is what Stripe charged, and
    // the amounts already have tax in them — so a refresh would price the
    // lines from purchased entities the order does not have and add VAT on
    // top of VAT. An order recording a payment is a record, not a quote.
    $order->setRefreshState(OrderInterface::REFRESH_SKIP);

    $order->setData(StripeSubscription::SUBSCRIPTION_KEY, $this->subscriptionOf($invoice)
      ?: (string) $subscription->getData(StripeSubscription::SUBSCRIPTION_KEY));
    $order->setData(self::INVOICE_KEY, (string) $invoice->id);
    $order->setData(self::RENEWS_KEY, (string) $subscription->id());
    $order->setData(self::INVOICE_URL_KEY, (string) ($invoice->hosted_invoice_url ?? ''));
    $order->setData(self::PERIOD_START_KEY, (int) ($invoice->period_start ?? 0));
    $order->setData(self::PERIOD_END_KEY, (int) ($invoice->period_end ?? 0));
    $this->recordIntent($order, $invoice);

    $order->save();

    foreach ($items as $item) {
      $item->save();
      $order->addItem($item);
    }

    $order->save();

    // Placed straight away. This order is not a cart anybody is filling and
    // never was: it is the record of a charge that has already happened, and
    // leaving it in draft would put it in front of whatever counts carts.
    $order->getState()->applyTransitionById('place');
    $order->save();

    $this->recordPayment($order, $invoice);

    $this->logger->notice('Stripe billed order @order again; renewal @renewal records invoice @invoice.', [
      '@order' => $subscription->id(),
      '@renewal' => $order->id(),
      '@invoice' => $reference,
    ]);

    return $order;
  }

  /**
   * The subscription an invoice was raised for.
   *
   * `$invoice->subscription` until the 2025 API versions, and under
   * `parent.subscription_details` from them on — an invoice can now have
   * parents of several kinds, and a subscription is one of them. Both are
   * read, so the module keeps working against an account that has not moved
   * yet and against one that has.
   *
   * @return string
   *   The id, or '' where the invoice was not raised by a subscription.
   */
  private function subscriptionOf(Invoice $invoice): string {
    $subscription = $invoice->parent->subscription_details->subscription
      ?? $invoice->subscription
      ?? NULL;

    // Expanded or not: Stripe sends the id as a string unless asked to
    // expand it, and an integration that assumed one of the two breaks the
    // first time somebody turns expansion on.
    if ($subscription instanceof RemoteSubscription) {
      return (string) $subscription->id;
    }

    return is_string($subscription) ? $subscription : '';
  }

  /**
   * What the renewal is called, which is what Stripe called it.
   *
   * Stripe's own invoice number where there is one — the customer has it on
   * the PDF they were sent, so it is the number they will quote — and the
   * invoice id otherwise. Either way it is unique to the charge, which is
   * what makes it usable as the check against handling an event twice.
   */
  private function referenceFor(Invoice $invoice): string {
    $number = trim((string) ($invoice->number ?? ''));

    return $number === '' ? (string) $invoice->id : $number;
  }

  /**
   * An order already recording this invoice, if there is one.
   */
  private function existing(string $reference): ?OrderInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_order');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_number', $reference)
      ->range(0, 1)
      ->execute();

    $order = $ids === [] ? NULL : $storage->load((int) reset($ids));

    return $order instanceof OrderInterface ? $order : NULL;
  }

  /**
   * The renewal's lines, taken from the invoice.
   *
   * From what Stripe charged rather than from what the subscription order
   * says, because the two can differ and Stripe is right when they do: a
   * customer who added an environment mid-period is billed for it by Stripe
   * whether or not anything here recalculated. Copying our own figures over
   * would produce an order that disagrees with the money that moved, which
   * is the one thing a record of a charge must not do.
   *
   * Falls back to the subscription's own lines only when the invoice carries
   * none, which should not happen and would otherwise mean no order at all.
   *
   * @return array<int, \Drupal\commerce_order\Entity\OrderItemInterface>
   *   Unsaved order items.
   */
  private function itemsFrom(Invoice $invoice, OrderInterface $subscription): array {
    $items = [];
    $currency = strtoupper((string) $invoice->currency);

    foreach ($invoice->lines->data ?? [] as $line) {
      $quantity = max(1, (int) ($line->quantity ?? 1));
      $total = (int) ($line->amount ?? 0);

      if ($total === 0) {
        // A line at nothing was not charged for. It belongs on the
        // subscription order, where it explains what is included; on a
        // record of a payment it is noise.
        continue;
      }

      $item = OrderItem::create([
        'type' => 'default',
        'quantity' => $quantity,
        'unit_price' => $this->minorUnits->fromMinorUnits(intdiv($total, $quantity), $currency),
      ]);

      // Overridden, or an order refresh would price the line from a
      // purchased entity it does not have and zero it.
      $item->setUnitPrice($item->getUnitPrice(), TRUE);
      $item->setTitle((string) ($line->description ?? $subscription->label()), TRUE);

      $items[] = $item;
    }

    if ($items !== []) {
      return $items;
    }

    foreach ($subscription->getItems() as $item) {
      $copy = $item->createDuplicate();
      $copy->enforceIsNew();
      $items[] = $copy;
    }

    return $items;
  }

  /**
   * Copies the order's own fields onto its renewal.
   *
   * Not Commerce's. Everything in COMMERCE_FIELDS either describes the
   * charge — its number, its state, its totals, when it was placed — or was
   * set deliberately above, and copying those across would produce a renewal
   * claiming to be the order it renews.
   *
   * What is left is whatever the site added to the order type, and that does
   * travel. A renewal that lost the team it belongs to would be invisible to
   * everything that scopes by tenant.
   */
  private function carryOver(OrderInterface $subscription, OrderInterface $order): void {
    foreach ($subscription->getFields(FALSE) as $name => $field) {
      // A site's own contract fields describe the contract, and only the
      // contract order carries them (Conventions::$renewalSkipFields).
      if (in_array($name, self::COMMERCE_FIELDS, TRUE)
        || in_array($name, $this->conventions->renewalSkipFields, TRUE)
        || $field->isEmpty()
        || !$order->hasField($name)) {
        continue;
      }

      $order->set($name, $field->getValue());
    }
  }

  /**
   * Settles an order Stripe has already collected on.
   *
   * For the *first* invoice, which is not a renewal and gets no order of its
   * own: the order was checked out here, Stripe billed it, and all that is
   * left is to write down that the money arrived. Renewals reach the same
   * two steps at the end of `fromInvoice()`.
   *
   * The invoice id is recorded as well as the payment, and it earns its keep
   * twice: somebody reconciling Stripe against the site searches on it,
   * and it is what tells a redelivered first event apart from a genuine
   * second period.
   */
  public function settle(OrderInterface $order, Invoice $invoice): void {
    // The subscription as well, where the order has none. The checkout's own
    // event writes it and may never have arrived, or been saved over; an
    // order left with no subscription id is one nothing can be added to.
    $subscription = $this->subscriptionOf($invoice);

    if ($subscription !== '' && (string) $order->getData(StripeSubscription::SUBSCRIPTION_KEY) === '') {
      $order->setData(StripeSubscription::SUBSCRIPTION_KEY, $subscription);
    }

    // The payment first, the recorded id last: the id is what makes a repeat
    // read as a redelivery, so a crash in between must leave the repeat able
    // to finish (the payment is idempotent by its remote id).
    $this->recordPayment($order, $invoice);
    $this->recordIntent($order, $invoice);

    $order->setData(self::INVOICE_KEY, (string) $invoice->id);
    $order->setData(self::INVOICE_URL_KEY, (string) ($invoice->hosted_invoice_url ?? ''));
    $order->save();
  }

  /**
   * A billing profile of the renewal's own, copied from the contract's.
   *
   * Sharing the contract's profile would let a later re-pricing of the
   * contract rewrite what this renewal was taxed with. Only where the site
   * names a profile field marking an order's copy
   * (Conventions::$profileCopyField); otherwise the profile is shared.
   */
  private function copyOfBillingProfile(OrderInterface $subscription): mixed {
    $profile = $subscription->getBillingProfile();
    $field = $this->conventions->profileCopyField;
    if ($profile === NULL || $field === '' || !$profile->hasField($field)) {
      return $profile;
    }
    $copy = $profile->createDuplicate();
    $copy->set($field, TRUE);
    $copy->save();
    return $copy;
  }

  /**
   * Records the invoice's payment intent on the order that mirrors it.
   *
   * So a refund or a dispute, which names the intent, finds the order of its
   * own invoice, whichever period it was. Only where the site names the
   * field (Conventions::$paymentIntentField); otherwise nothing is recorded.
   */
  private function recordIntent(OrderInterface $order, Invoice $invoice): void {
    $intent = $invoice->payment_intent ?? $invoice->payments->data[0]->payment->payment_intent ?? NULL;
    $intent = is_object($intent) ? ($intent->id ?? NULL) : $intent;

    $field = $this->conventions->paymentIntentField;

    if ($field !== '' && $order->hasField($field) && is_string($intent) && $intent !== '') {
      $order->set($field, $intent);
    }
  }

  /**
   * Records the money, so the order is paid rather than merely placed.
   *
   * The invoice is settled by the time this runs: that is what `invoice.paid`
   * means, even for a zero invoice (a 100 % coupon, a credit balance), which
   * settles the order deliberately and records a zero-amount payment. The
   * payment is completed against the gateway that took it, carrying Stripe's
   * invoice id as the remote reference; **idempotent by that id**, so a retry
   * after a crash writes none twice and still brings the order's total paid
   * up to date.
   */
  private function recordPayment(OrderInterface $order, Invoice $invoice): void {
    $gateway = (string) $order->get('payment_gateway')->target_id;

    if ($gateway === '') {
      // Nothing to attribute it to.
      return;
    }

    $amount = $this->minorUnits->fromMinorUnits(max(0, (int) ($invoice->amount_paid ?? 0)), strtoupper((string) $invoice->currency));
    $storage = $this->entityTypeManager->getStorage('commerce_payment');
    $existing = $storage->loadByProperties([
      'order_id' => $order->id(),
      'remote_id' => (string) $invoice->id,
    ]);

    if ($existing === []) {
      $storage->create([
        'type' => 'payment_default',
        'payment_gateway' => $gateway,
        'order_id' => $order->id(),
        'amount' => $amount,
        'state' => 'completed',
        'remote_id' => (string) $invoice->id,
        'completed' => (int) ($invoice->status_transitions->paid_at ?? time()),
      ])->save();
    }

    $order->setTotalPaid($amount);
    $order->save();
  }

}
