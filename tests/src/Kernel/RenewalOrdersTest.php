<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_recurring\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\Store;
use Drupal\commerce_store\Entity\StoreType;
use Drupal\commerce_stripe_recurring\Conventions;
use Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription;
use Drupal\commerce_stripe_recurring\RenewalOrders;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;
use Stripe\Invoice;

/**
 * The order that records a period Stripe has already charged for.
 *
 * This is a ledger, not a quote. Everything here is about that distinction:
 * the amounts are what moved rather than what the catalogue says, they are
 * never re-priced, and an event delivered twice must not produce a second
 * document saying a customer was billed twice.
 *
 * "Log in to Stripe and look" is not an answer to "what did I pay in March",
 * and this is the code that makes it unnecessary — verified until now by
 * hand against a live subscription.
 *
 * `PaymentTypeManager` answers with an empty definition list under a kernel
 * test — see `commerce_stripe_recurring_test`, which supplies what discovery
 * does not find. Without it no `commerce_payment` can be built at all, and
 * the half of this class that records the money would be untestable.
 */
#[Group('commerce_stripe_recurring')]
#[CoversClass(RenewalOrders::class)]
#[RunTestsInSeparateProcesses]
final class RenewalOrdersTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'system', 'user', 'field', 'options', 'path', 'path_alias',
    'entity_reference_revisions', 'address', 'state_machine', 'datetime',
    'commerce', 'commerce_price', 'commerce_store', 'commerce_product',
    'commerce_order', 'commerce_payment', 'profile', 'commerce_number_pattern', 'views',
    'commerce_stripe_recurring_test',
    'commerce_stripe_recurring',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('profile');
    $this->installConfig(['commerce_price', 'commerce_payment']);

    Currency::create([
      'currencyCode' => 'EUR',
      'name' => 'Euro',
      'numericCode' => '978',
      'symbol' => '€',
      'fractionDigits' => 2,
    ])->save();

    OrderItemType::create([
      'id' => 'default',
      'label' => 'Default',
      'orderType' => 'default',
      'purchasableEntityType' => 'commerce_product_variation',
    ])->save();
    OrderType::create([
      'id' => 'default',
      'label' => 'Default',
      'workflow' => 'order_default',
      'refresh_mode' => 'customer',
      'refresh_frequency' => 30,
    ])->save();

    // The team an order belongs to, standing in for whatever a site
    // hangs off an order that Commerce knows nothing about.
    FieldStorageConfig::create([
      'field_name' => 'field_team_ref',
      'entity_type' => 'commerce_order',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_team_ref',
      'entity_type' => 'commerce_order',
      'bundle' => 'default',
      'label' => 'Team',
    ])->save();

    PaymentGateway::create([
      'id' => 'stripe_subscription',
      'label' => 'Stripe',
      'plugin' => 'manual',
      'configuration' => [],
    ])->save();

    User::create(['uid' => 0, 'name' => ''])->save();
    User::create(['uid' => 1, 'name' => 'operator'])->save();

    StoreType::create(['id' => 'online', 'label' => 'Online'])->save();
    Store::create([
      'type' => 'online',
      'uid' => 1,
      'name' => 'Example store',
      'mail' => 'billing@example.test',
      'address' => ['country_code' => 'HR'],
      'default_currency' => 'EUR',
      'billing_countries' => ['HR'],
    ])->save();

  }

  /**
   * A renewal records what Stripe charged, line by line.
   */
  public function testTheRenewalMirrorsTheInvoice(): void {
    $order = $this->renew($this->invoice(lines: [
      ['Plan S — monthly', 1250, 1],
      ['Backups', 750, 1],
    ]));

    $this->assertInstanceOf(OrderInterface::class, $order);

    $titles = array_map(static fn ($item): string => (string) $item->getTitle(), $order->getItems());
    $this->assertSame(['Plan S — monthly', 'Backups'], $titles);

    // Compared as numbers: how many decimal places Commerce pads the column
    // to is its business, and pinning the string would fail on a precision
    // change that costs nobody anything.
    $amounts = array_map(static fn ($item): float => (float) $item->getUnitPrice()->getNumber(), $order->getItems());
    $this->assertSame([12.5, 7.5], $amounts);
  }

  /**
   * The same invoice delivered twice produces one order.
   *
   * Stripe re-sends an event when it is unsure we heard it. A retry that
   * created a second order would be a customer billed twice on paper, which
   * is worse than the missing order this code exists to stop.
   */
  public function testInvoiceDeliveredTwiceIsRecordedOnce(): void {
    $subscription = $this->subscription();
    $invoice = $this->invoice(lines: [['Plan S', 1250, 1]]);

    $first = $this->renewalOrders()->fromInvoice($subscription, $invoice);
    $second = $this->renewalOrders()->fromInvoice($subscription, $invoice);

    $this->assertSame($first->id(), $second->id(), 'A repeated invoice made a second order.');
    $this->assertCount(2, Order::loadMultiple(), 'A repeated invoice made a second order.');
  }

  /**
   * A line Stripe charged nothing for is left off.
   *
   * It belongs on the subscription order, where it explains what is included.
   * On a record of a payment it is noise.
   */
  public function testLineChargedNothingIsLeftOff(): void {
    $order = $this->renew($this->invoice(lines: [
      ['Plan S', 1250, 1],
      ['Included environment', 0, 1],
    ]));

    $this->assertCount(1, $order->getItems());
  }

  /**
   * Several of a thing are priced per one, not per line.
   *
   * Stripe reports the line's total; an order item wants what one costs. Read
   * the wrong way round, two environments at 7.50 become two at 15.00 and the
   * order says a customer paid twice what they did.
   */
  public function testSeveralOfOneThingArePricedPerUnit(): void {
    $order = $this->renew($this->invoice(lines: [['Environment', 1500, 2]]));

    $item = $order->getItems()[0];

    $this->assertSame(7.5, (float) $item->getUnitPrice()->getNumber());
    $this->assertSame(2.0, (float) $item->getQuantity());
  }

  /**
   * A renewal is never re-priced.
   *
   * The amounts already have tax in them, so a refresh would price the lines
   * from purchased entities the order does not have and add VAT on top of
   * VAT. A record of a charge is not a quote.
   */
  public function testRenewalIsNeverRepriced(): void {
    $order = $this->renew($this->invoice(lines: [['Plan S', 1250, 1]]));

    // Asserted on the money rather than on the flag. `refresh_state` is not
    // kept once the order is saved, and what the flag is *for* is that the
    // total stays what Stripe charged — a refresh would price the line from
    // a purchased entity this order does not have and add VAT to an amount
    // that already includes it.
    $this->assertSame(12.5, (float) $order->getTotalPrice()->getNumber());
    $this->assertCount(0, $order->collectAdjustments(), 'The renewal was given adjustments of its own.');
  }

  /**
   * The money is recorded, not merely the document.
   *
   * An order that says a renewal happened but records no payment is a ledger
   * with a hole in it: the invoice exists, the total is right, and nothing
   * says anybody paid.
   */
  public function testThePaymentIsRecorded(): void {
    $order = $this->renew($this->invoice(lines: [['Plan S', 1250, 1]], paid: 1250));

    $payments = $this->container->get('entity_type.manager')
      ->getStorage('commerce_payment')
      ->loadByProperties(['order_id' => $order->id()]);

    $this->assertCount(1, $payments, 'The renewal records no payment.');

    $payment = reset($payments);
    $this->assertInstanceOf(PaymentInterface::class, $payment);

    $this->assertSame('completed', $payment->getState()->getId());
    $this->assertSame(12.5, (float) $payment->getAmount()->getNumber());
    // What somebody reconciling the two sides searches on.
    $this->assertSame('in_test', $payment->getRemoteId());
    $this->assertSame(12.5, (float) $order->getTotalPaid()->getNumber());
  }

  /**
   * An invoice that collected nothing records a zero-amount payment, once.
   *
   * A paid zero invoice (a full coupon, a credit balance) settles its order
   * deliberately, and the payment names the invoice, so a retry repeats none.
   */
  public function testInvoiceThatCollectedNothingRecordsZeroPaymentOnce(): void {
    $subscription = $this->subscription();
    $invoice = $this->invoice(lines: [['Plan S', 1250, 1]], paid: 0);
    $order = $this->renewalOrders()->fromInvoice($subscription, $invoice);
    $this->assertInstanceOf(OrderInterface::class, $order);
    $this->renewalOrders()->fromInvoice($subscription, $invoice);

    $payments = $this->container->get('entity_type.manager')
      ->getStorage('commerce_payment')
      ->loadByProperties(['order_id' => $order->id()]);

    $this->assertCount(1, $payments);
    $payment = reset($payments);
    $this->assertInstanceOf(PaymentInterface::class, $payment);
    $this->assertSame(0.0, (float) $payment->getAmount()->getNumber());
  }

  /**
   * A renewal whose payment was lost to a crash is repaired by the retry.
   */
  public function testRetryAfterCrashBeforeThePaymentWritesItOnce(): void {
    $subscription = $this->subscription();
    $invoice = $this->invoice(lines: [['Plan S', 1250, 1]]);
    $order = $this->renewalOrders()->fromInvoice($subscription, $invoice);
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment');

    // The crash: the order exists, its payment does not.
    $storage->delete($storage->loadByProperties(['order_id' => $order->id()]));
    $this->assertCount(0, $storage->loadByProperties(['order_id' => $order->id()]));

    $this->renewalOrders()->fromInvoice($subscription, $invoice);
    $this->renewalOrders()->fromInvoice($subscription, $invoice);

    $this->assertCount(1, $storage->loadByProperties(['order_id' => $order->id()]));
  }

  /**
   * The order that was checked out is settled, not merely placed.
   *
   * The first invoice gets no order of its own — it is paying for the one
   * the customer checked out — and for a long time that meant it got no
   * payment either. Every renewal recorded one, so a Stripe customer's
   * billing page listed their first period unpaid beside months that were
   * settled, and its pro-forma showed a full balance and an empty payments
   * section. Nothing was wrong with the money; the platform had simply not
   * written it down.
   */
  public function testSettlingTheFirstInvoiceRecordsThePayment(): void {
    $order = $this->subscription();

    $this->renewalOrders()->settle($order, $this->invoice(
      lines: [['Plan S', 1250, 1]],
      paid: 1250,
    ));

    $payments = $this->container->get('entity_type.manager')
      ->getStorage('commerce_payment')
      ->loadByProperties(['order_id' => $order->id()]);

    $this->assertCount(1, $payments, 'The first invoice recorded no payment.');

    $payment = reset($payments);
    $this->assertInstanceOf(PaymentInterface::class, $payment);

    $this->assertSame('completed', $payment->getState()->getId());
    $this->assertSame(12.5, (float) $payment->getAmount()->getNumber());
    $this->assertSame('in_test', $payment->getRemoteId());
    $this->assertSame(12.5, (float) $order->getTotalPaid()->getNumber());
    $this->assertTrue($order->getBalance()->isZero(), 'A settled order still owes money.');
  }

  /**
   * Settling writes the invoice id down, which is what stops a repeat.
   *
   * Stripe redelivers an event it is unsure was heard. By the time the
   * second copy arrives the order is placed rather than draft, so the branch
   * that handles a first invoice no longer matches it — and `fromInvoice()`
   * would not catch it either, because its own guard matches on the order
   * number and this order carries the platform's rather than Stripe's. The
   * id recorded here is what the webhook compares instead.
   */
  public function testSettlingRecordsTheInvoiceId(): void {
    $order = $this->subscription();

    $this->renewalOrders()->settle($order, $this->invoice(lines: [['Plan S', 1250, 1]]));

    $this->assertSame(
      'in_test',
      (string) $order->getData(RenewalOrders::INVOICE_KEY),
      'A redelivered first invoice would be booked as a second period.',
    );
  }

  /**
   * Settling leaves the order number alone.
   *
   * The reason the guard above has to exist. A renewal is *named* after the
   * invoice, which is how `fromInvoice()` recognizes one it has already
   * made; the order somebody checked out keeps the number this platform
   * gave it, so that test can never match it however many times Stripe
   * sends the event.
   */
  public function testSettlingLeavesTheOrderNumberAlone(): void {
    $order = $this->subscription();

    $this->renewalOrders()->settle($order, $this->invoice(
      lines: [['Plan S', 1250, 1]],
      number: 'INV-0042',
    ));

    $this->assertSame('INV-0001', $order->getOrderNumber(), 'The checkout order was renamed after a Stripe invoice.');
  }

  /**
   * A first invoice that collected nothing records a zero payment once.
   */
  public function testSettlingAnInvoiceThatCollectedNothingRecordsZeroPaymentOnce(): void {
    $order = $this->subscription();
    $invoice = $this->invoice(lines: [['Plan S', 1250, 1]], paid: 0);

    $this->renewalOrders()->settle($order, $invoice);
    $this->renewalOrders()->settle($order, $invoice);

    $this->assertCount(1, $this->container->get('entity_type.manager')
      ->getStorage('commerce_payment')
      ->loadByProperties(['order_id' => $order->id()]));
  }

  /**
   * The renewal is called what Stripe called it.
   *
   * The number is on the PDF they were sent, so it is what they will quote.
   */
  public function testTheRenewalIsNamedAfterTheInvoice(): void {
    $this->assertSame('INV-0042', $this->renew($this->invoice(
      lines: [['Plan S', 1250, 1]],
      number: 'INV-0042',
    ))->getOrderNumber());
  }

  /**
   * Without a number of its own it is named after the invoice id.
   */
  public function testWithoutNumberTheInvoiceIdIsUsed(): void {
    $this->assertSame('in_test', $this->renew($this->invoice(
      lines: [['Plan S', 1250, 1]],
      number: '',
    ))->getOrderNumber());
  }

  /**
   * An invoice with no lines falls back to the subscription's own.
   *
   * Should not happen, and would otherwise mean no record of the charge at
   * all — which is the failure this whole class exists to prevent.
   */
  public function testInvoiceWithNoLinesFallsBackToTheSubscription(): void {
    $order = $this->renew($this->invoice(lines: []));

    $this->assertInstanceOf(OrderInterface::class, $order);
    $this->assertCount(1, $order->getItems());
    $this->assertSame('Plan S — monthly', (string) $order->getItems()[0]->getTitle());
  }

  /**
   * What the site hangs off an order travels to its renewals.
   *
   * A renewal that lost the team it belongs to would be invisible to
   * everything that scopes by tenant.
   */
  public function testTheAccountTravelsToTheRenewal(): void {
    $order = $this->renew($this->invoice(lines: [['Plan S', 1250, 1]]));

    $this->assertSame('dk0iz7', $order->get('field_team_ref')->value);
  }

  /**
   * A field the site names as the contract's own stays on the contract.
   *
   * And the payment intent is written where the site names a field for it.
   */
  public function testTheSitesConventionsAreFollowed(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_intent',
      'entity_type' => 'commerce_order',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_intent',
      'entity_type' => 'commerce_order',
      'bundle' => 'default',
      'label' => 'Payment intent',
    ])->save();

    $invoice = $this->invoice(lines: [['Plan S', 1250, 1]]);
    $invoice->offsetSet('payment_intent', 'pi_renewal');

    $order = $this->renewalOrders(new Conventions([
      'renewal_skip_fields' => ['field_team_ref'],
      'payment_intent_field' => 'field_intent',
    ]))->fromInvoice($this->subscription(), $invoice);

    $this->assertInstanceOf(OrderInterface::class, $order);
    $this->assertTrue($order->get('field_team_ref')->isEmpty(), 'A field the site keeps on the contract traveled.');
    $this->assertSame('pi_renewal', $order->get('field_intent')->value);

    // Without the conventions, the same field travels and no intent is kept.
    $plain = $this->renewalOrders()->fromInvoice($this->subscription(), $this->invoice(lines: [['Plan S', 1250, 1]], number: 'INV-0043'));
    $this->assertInstanceOf(OrderInterface::class, $plain);
    $this->assertSame('dk0iz7', $plain->get('field_team_ref')->value);
    $this->assertTrue($plain->get('field_intent')->isEmpty());
  }

  /**
   * What describes the charge does not travel.
   *
   * A renewal carrying the number, state or totals of the order it renews
   * would be a document claiming to be another document.
   */
  public function testTheChargesOwnFieldsDoNotTravel(): void {
    $subscription = $this->subscription();
    $order = $this->renewalOrders()->fromInvoice($subscription, $this->invoice(
      lines: [['Plan S', 1250, 1]],
      number: 'INV-0042',
    ));

    $this->assertNotSame($subscription->getOrderNumber(), $order->getOrderNumber());
    $this->assertNotSame($subscription->id(), $order->id());

    // What does travel is the pair of references that make the renewal
    // findable from either end.
    $this->assertSame('in_test', $order->getData(RenewalOrders::INVOICE_KEY));
    $this->assertSame((string) $subscription->id(), (string) $order->getData(RenewalOrders::RENEWS_KEY));
  }

  /**
   * The period the renewal covers is recorded, from the invoice.
   */
  public function testThePeriodIsRecorded(): void {
    $order = $this->renew($this->invoice(lines: [['Plan S', 1250, 1]]));

    $this->assertSame(1_700_000_000, $order->getData(RenewalOrders::PERIOD_START_KEY));
    $this->assertSame(1_702_592_000, $order->getData(RenewalOrders::PERIOD_END_KEY));
  }

  /**
   * Makes the renewal for an invoice against a fresh subscription order.
   */
  private function renew(Invoice $invoice): OrderInterface {
    $order = $this->renewalOrders()->fromInvoice($this->subscription(), $invoice);

    $this->assertInstanceOf(OrderInterface::class, $order);

    return $order;
  }

  /**
   * The service under test.
   */
  private function renewalOrders(?Conventions $conventions = NULL): RenewalOrders {
    return new RenewalOrders(
      $this->container->get('entity_type.manager'),
      $this->container->get('commerce_price.minor_units_converter'),
      new NullLogger(),
      $conventions ?? new Conventions(),
    );
  }

  /**
   * The order a subscription was bought on.
   */
  private function subscription(): OrderInterface {
    $existing = Order::loadMultiple();

    foreach ($existing as $order) {
      if ($order->getOrderNumber() === 'INV-0001') {
        return $order;
      }
    }

    $item = OrderItem::create(['type' => 'default', 'quantity' => 1]);
    $item->setUnitPrice(new Price('12.50', 'EUR'), TRUE);
    $item->setTitle('Plan S — monthly', TRUE);
    $item->save();

    $order = Order::create([
      'type' => 'default',
      'state' => 'completed',
      'store_id' => 1,
      'uid' => 1,
      'mail' => 'anna@example.test',
      'order_number' => 'INV-0001',
      'payment_gateway' => 'stripe_subscription',
      'order_items' => [$item],
      'field_team_ref' => 'dk0iz7',
    ]);
    $order->setData(StripeSubscription::SUBSCRIPTION_KEY, 'sub_test');
    $order->save();

    return $order;
  }

  /**
   * A Stripe invoice, as the API describes one.
   *
   * @param array<int, array{string, int, int}> $lines
   *   Description, total in minor units, quantity.
   * @param int $paid
   *   What the invoice collected, in minor units.
   * @param string $number
   *   Stripe's own invoice number, or an empty string for one without.
   */
  private function invoice(array $lines, int $paid = 1250, string $number = 'INV-0042'): Invoice {
    $data = [];

    foreach ($lines as [$description, $amount, $quantity]) {
      $data[] = ['description' => $description, 'amount' => $amount, 'quantity' => $quantity];
    }

    /** @var \Stripe\Invoice $invoice */
    $invoice = Invoice::constructFrom([
      'id' => 'in_test',
      'number' => $number,
      'currency' => 'eur',
      'amount_paid' => $paid,
      'hosted_invoice_url' => 'https://stripe.test/i/in_test',
      'period_start' => 1_700_000_000,
      'period_end' => 1_702_592_000,
      'status_transitions' => ['paid_at' => 1_700_000_500],
      'lines' => ['data' => $data],
      'parent' => ['subscription_details' => ['subscription' => 'sub_test']],
    ]);

    return $invoice;
  }

}
