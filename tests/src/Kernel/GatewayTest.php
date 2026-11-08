<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_recurring\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\Store;
use Drupal\commerce_store\Entity\StoreType;
use Drupal\commerce_stripe_recurring\OrderEvents;
use Drupal\commerce_stripe_recurring\RenewalOrders;
use Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Stripe\Invoice;
use Stripe\Checkout\Session;

/**
 * Which of the three things an `invoice.paid` event is.
 *
 * Three cases share one event type and they are not remotely the same. The
 * first invoice pays for the order the customer checked out. A redelivery is
 * Stripe repeating itself because it was unsure the first telling arrived.
 * Any later invoice is a new period that wants an order of its own.
 *
 * **Telling them apart used to be done by the order's state, and that was
 * wrong in the arrangement this platform actually runs.** Checkout places
 * the order itself, so by the time the event arrives the order is
 * `completed` rather than `draft` — which meant the first invoice was read
 * as a renewal and booked a second order for a period the checkout order
 * already covered, while the checkout order itself never recorded its
 * payment and stood at a full balance for ever.
 *
 * Both halves of that are asserted here, because they are one bug: the id
 * recorded against the contract is what decides, and recording it is also
 * what makes the payment land on the right order.
 */
#[Group('commerce_stripe_recurring')]
#[CoversClass(OrderEvents::class)]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class GatewayTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'system', 'user', 'field', 'options', 'path', 'path_alias',
    'entity_reference_revisions', 'address', 'state_machine', 'datetime',
    'commerce', 'commerce_price', 'commerce_store', 'commerce_product',
    'commerce_order', 'commerce_payment', 'profile', 'commerce_number_pattern',
    'views', 'commerce_stripe_recurring_test', 'commerce_stripe_recurring',
  ];

  /**
   * {@inheritdoc}
   *
   * The owner is the order's `account`, sent as its uuid: the cross-check
   * testMetadataOfAnotherAccountRefusesTheEvent() exercises.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->setParameter('commerce_stripe_recurring.conventions', [
      'owner_field' => 'account',
      'owner_metadata_key' => 'account_uuid',
    ]);
  }

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

    User::create(['uid' => 0, 'name' => ''])->save();
    User::create(['uid' => 1, 'name' => 'operator'])->save();

    StoreType::create(['id' => 'online', 'label' => 'Online'])->save();
    Store::create([
      'type' => 'online',
      'uid' => 1,
      'name' => 'Example store',
      'mail' => 'store@example.test',
      'address' => ['country_code' => 'HR'],
      'default_currency' => 'EUR',
      'billing_countries' => ['HR'],
    ])->save();

    PaymentGateway::create([
      'id' => 'stripe_subscription',
      'label' => 'Stripe',
      'plugin' => 'manual',
      'configuration' => ['display_label' => 'Stripe', 'instructions' => ['value' => '', 'format' => 'plain_text']],
    ])->save();
  }

  /**
   * The order Checkout already placed is still settled by its first invoice.
   *
   * The regression, stated as plainly as it can be: `completed` is what
   * Checkout leaves behind, and it must not be read as "this has been
   * invoiced before".
   */
  public function testFirstInvoiceSettlesAnAlreadyPlacedOrder(): void {
    $order = $this->contract();

    $this->assertSame('completed', $order->getState()->getId(), 'The fixture does not reproduce what Checkout leaves.');

    $this->deliver($this->invoice('in_first', 'INV-0042', $order->id()));

    $order = $this->reload($order->id());

    $this->assertSame(12.5, (float) $order->getTotalPaid()->getNumber(), 'The checkout order recorded no payment.');
    $this->assertTrue($order->getBalance()->isZero(), 'The checkout order still owes money.');
    $this->assertCount(1, $this->paymentsFor($order->id()));
    $this->assertCount(1, Order::loadMultiple(), 'The first invoice was booked as a second order.');
  }

  /**
   * The first invoice records the id that tells a repeat apart.
   */
  public function testFirstInvoiceRecordsItsId(): void {
    $order = $this->contract();

    $this->deliver($this->invoice('in_first', 'INV-0042', $order->id()));

    $this->assertSame('in_first', (string) $this->reload($order->id())->getData(RenewalOrders::INVOICE_KEY));
  }

  /**
   * The first invoice writes the subscription down where the order lost it.
   *
   * The checkout's own event writes it and arrives beside this one; an order
   * saved by both can come out without it, and then nothing can be added to
   * its next invoice.
   */
  public function testFirstInvoiceRecordsTheSubscription(): void {
    $order = $this->contract();
    $order->unsetData(StripeSubscription::SUBSCRIPTION_KEY)->save();

    $this->assertTrue($this->deliver($this->invoice('in_first', 'INV-0042', $order->id())));

    $reloaded = Order::load($order->id());
    $this->assertNotNull($reloaded);
    $this->assertSame('sub_test', (string) $reloaded->getData(StripeSubscription::SUBSCRIPTION_KEY));
  }

  /**
   * Stripe repeating itself changes nothing.
   *
   * Redelivery is normal, not exceptional: Stripe resends whenever it is
   * unsure the endpoint heard. A second copy that booked a second order
   * would bill a customer twice on paper for one period.
   */
  public function testTheFirstInvoiceRedeliveredIsIgnored(): void {
    $order = $this->contract();
    $invoice = $this->invoice('in_first', 'INV-0042', $order->id());

    $this->assertTrue($this->deliver($invoice), 'The first delivery did nothing.');
    $this->assertFalse($this->deliver($invoice), 'A redelivery was treated as new.');
    $this->assertFalse($this->deliver($invoice), 'A third copy was treated as new.');

    $this->assertCount(1, Order::loadMultiple(), 'A redelivered first invoice booked another order.');
    $this->assertCount(1, $this->paymentsFor($order->id()), 'A redelivered first invoice was paid twice.');
  }

  /**
   * A genuinely later invoice still gets an order of its own.
   *
   * The other half of the rule. Guarding against repeats must not swallow
   * the second period, which is a separate charge on a separate date.
   */
  public function testLaterInvoiceBecomesItsOwnOrder(): void {
    $order = $this->contract();

    $this->deliver($this->invoice('in_first', 'INV-0042', $order->id()));
    $this->deliver($this->invoice('in_second', 'INV-0043', $order->id()));

    $orders = Order::loadMultiple();

    $this->assertCount(2, $orders, 'The second period got no order.');

    $numbers = array_map(static fn (Order $o): string => (string) $o->getOrderNumber(), array_values($orders));
    sort($numbers);

    $this->assertSame(['INV-0001', 'INV-0043'], $numbers, 'The renewal is not named after its invoice.');
  }

  /**
   * A renewal redelivered is caught too, by the renewal's own guard.
   */
  public function testLaterInvoiceRedeliveredIsIgnored(): void {
    $order = $this->contract();

    $this->deliver($this->invoice('in_first', 'INV-0042', $order->id()));
    $this->deliver($this->invoice('in_second', 'INV-0043', $order->id()));
    $this->deliver($this->invoice('in_second', 'INV-0043', $order->id()));

    $this->assertCount(2, Order::loadMultiple(), 'A redelivered renewal booked a third order.');
  }

  /**
   * An order still in draft is placed as well as settled.
   *
   * The arrangement where Checkout has not placed it — an abandoned return
   * leg, or a flow that defers placement. Both halves have to happen.
   */
  public function testDraftOrderIsPlacedAndSettled(): void {
    $order = $this->contract(state: 'draft');

    $this->deliver($this->invoice('in_first', 'INV-0042', $order->id()));

    $order = $this->reload($order->id());

    $this->assertSame('completed', $order->getState()->getId(), 'A draft order was left in draft.');
    $this->assertSame(12.5, (float) $order->getTotalPaid()->getNumber());
  }

  /**
   * The €0 invoice a trial starts with is not the first payment.
   *
   * Recorded, it would make the order look paid for a period nobody paid
   * for, and the real first charge after the trial a renewal of it.
   */
  public function testTheTrialInvoiceIsNotTheFirstPayment(): void {
    $order = $this->contract();
    $order->setData(StripeSubscription::TRIAL_KEY, 3)->save();

    $trial = $this->invoice('in_trial', 'INV-0041', $order->id());
    $trial->amount_paid = 0;

    $this->assertTrue($this->deliver($trial));
    $this->assertSame('', (string) $this->reload($order->id())->getData(RenewalOrders::INVOICE_KEY), 'The trial invoice was recorded as the first.');
    $this->assertCount(0, $this->paymentsFor($order->id()));

    // And the charge when the trial ends settles the checkout order, rather
    // than becoming an order of its own.
    $this->deliver($this->invoice('in_first', 'INV-0042', $order->id()));

    $this->assertSame('in_first', (string) $this->reload($order->id())->getData(RenewalOrders::INVOICE_KEY));
    $this->assertCount(1, Order::loadMultiple(), 'The first real charge was booked as a renewal.');
    $this->assertCount(1, $this->paymentsFor($order->id()));
  }

  /**
   * Nothing but `invoice.paid` pays an order.
   *
   * A checkout that completed, a failed collection and a subscription that
   * was created are all things Stripe says before money has moved; a card
   * settles in seconds and a direct debit in days, so an order treating any
   * of them as payment is right about cards and wrong about every bank
   * instrument.
   */
  public function testOrdersArePaidOnlyByInvoicePaid(): void {
    $order = $this->contract(state: 'draft');

    $session = Session::constructFrom([
      'id' => 'cs_test',
      'object' => 'checkout.session',
      'mode' => 'subscription',
      'client_reference_id' => (string) $order->id(),
      'subscription' => 'sub_test',
      'customer' => 'cus_new',
      'payment_status' => 'unpaid',
    ]);
    $events = $this->container->get('commerce_stripe_recurring.order_events');

    $events->handle('checkout.session.completed', $session);
    $events->handle('invoice.payment_failed', $this->invoice('in_failed', 'INV-0040', $order->id()));

    $order = $this->reload($order->id());
    $this->assertSame('draft', $order->getState()->getId(), 'An order was placed before any invoice was paid.');
    $this->assertTrue($order->getTotalPaid() === NULL || $order->getTotalPaid()->isZero(), 'An order recorded money before an invoice was paid.');
    $this->assertCount(0, $this->paymentsFor($order->id()));
    $this->assertSame('cus_new', $order->getData(StripeSubscription::CUSTOMER_KEY), 'The checkout did not record its customer.');

    $this->deliver($this->invoice('in_first', 'INV-0042', $order->id()));

    $order = $this->reload($order->id());
    $this->assertSame('completed', $order->getState()->getId());
    $this->assertCount(1, $this->paymentsFor($order->id()));
  }

  /**
   * The recorded invoice id decides: none, the same, or another.
   */
  public function testInvoiceRoutingFollowsTheRecordedId(): void {
    $order = $this->contract();

    $this->assertTrue($this->deliver($this->invoice('in_a', 'INV-0042', $order->id())), 'No id recorded: the first invoice was not settled.');
    $this->assertFalse($this->deliver($this->invoice('in_a', 'INV-0042', $order->id())), 'The same id again: a redelivery was acted on.');
    $this->assertTrue($this->deliver($this->invoice('in_b', 'INV-0043', $order->id())), 'A different id: the new period got no order.');
    $this->assertCount(2, Order::loadMultiple());
  }

  /**
   * Metadata naming another account never moves money onto this order.
   */
  public function testMetadataOfAnotherAccountRefusesTheEvent(): void {
    FieldStorageConfig::create([
      'field_name' => 'account',
      'entity_type' => 'commerce_order',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'user'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'account',
      'entity_type' => 'commerce_order',
      'bundle' => 'default',
      'label' => 'Account',
    ])->save();

    $order = $this->contract();
    $order->set('account', 1)->save();

    $invoice = $this->invoice('in_x', 'INV-0042', $order->id());
    $invoice->parent->subscription_details->metadata['account_uuid'] = 'someone-else';

    $this->assertFalse($this->deliver($invoice), 'An event naming another account was handled.');
    $this->assertCount(0, $this->paymentsFor($order->id()));

    $invoice->parent->subscription_details->metadata['account_uuid'] = User::load(1)->uuid();
    $this->assertTrue($this->deliver($invoice), 'An event naming this order\'s own account was refused.');
  }

  /**
   * A zero invoice settles the order on purpose; a redelivery adds nothing.
   */
  public function testZeroInvoiceSettlesTheOrderOnce(): void {
    $order = $this->contract(state: 'draft');
    $invoice = $this->invoice('in_zero', 'INV-0050', $order->id());
    $invoice->amount_paid = 0;

    $this->assertTrue($this->deliver($invoice));
    $this->assertFalse($this->deliver($invoice), 'A redelivered zero invoice was acted on.');

    $order = $this->reload($order->id());
    $this->assertSame('completed', $order->getState()->getId());
    $this->assertSame('in_zero', (string) $order->getData(RenewalOrders::INVOICE_KEY));
    $payments = $this->paymentsFor($order->id());
    $this->assertCount(1, $payments);
    $this->assertSame(0.0, (float) reset($payments)->getAmount()->getNumber());
  }

  /**
   * A crash between the payment and the invoice id is finished by the retry.
   */
  public function testRetryAfterHalfSettledOrderRecordsOnePayment(): void {
    $order = $this->contract();
    $invoice = $this->invoice('in_half', 'INV-0051', $order->id());

    // The crash: the payment was written, the invoice id was not.
    $this->container->get('entity_type.manager')->getStorage('commerce_payment')->create([
      'type' => 'payment_default',
      'payment_gateway' => 'stripe_subscription',
      'order_id' => $order->id(),
      'amount' => new Price('12.50', 'EUR'),
      'state' => 'completed',
      'remote_id' => 'in_half',
    ])->save();

    $this->assertTrue($this->deliver($invoice));
    $this->assertFalse($this->deliver($invoice));

    $this->assertCount(1, $this->paymentsFor($order->id()), 'The retry wrote a second payment.');
    $this->assertSame('in_half', (string) $this->reload($order->id())->getData(RenewalOrders::INVOICE_KEY));
  }

  /**
   * An invoice of another subscription or customer than the order is refused.
   */
  public function testInvoiceOfAnotherSubscriptionOrCustomerIsRefused(): void {
    $order = $this->contract();
    $order->setData(StripeSubscription::CUSTOMER_KEY, 'cus_mine')->save();

    $other = $this->invoice('in_x', 'INV-0052', $order->id());
    $other->parent->subscription_details->subscription = 'sub_other';
    $this->assertFalse($this->deliver($other), 'An invoice of another subscription was applied.');

    $stranger = $this->invoice('in_y', 'INV-0053', $order->id());
    $stranger->customer = 'cus_other';
    $this->assertFalse($this->deliver($stranger), 'An invoice of another customer was applied.');
    $this->assertCount(0, $this->paymentsFor($order->id()));

    $mine = $this->invoice('in_z', 'INV-0054', $order->id());
    $mine->customer = 'cus_mine';
    $this->assertTrue($this->deliver($mine));
  }

  /**
   * Hands the event to the order events and says whether it acted.
   */
  private function deliver(Invoice $invoice, string $type = 'invoice.paid'): bool {
    return $this->container->get('commerce_stripe_recurring.order_events')->handle($type, $invoice);
  }

  /**
   * The payments recorded against an order.
   *
   * @return array<int, \Drupal\commerce_payment\Entity\PaymentInterface>
   *   The payments.
   */
  private function paymentsFor(int|string $id): array {
    /** @var array<int, \Drupal\commerce_payment\Entity\PaymentInterface> $payments */
    $payments = $this->container->get('entity_type.manager')
      ->getStorage('commerce_payment')
      ->loadByProperties(['order_id' => $id]);

    return $payments;
  }

  /**
   * An order reloaded from storage rather than from memory.
   */
  private function reload(int|string $id): Order {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_order');
    $storage->resetCache([$id]);

    $order = $storage->load($id);
    assert($order instanceof Order);

    return $order;
  }

  /**
   * The order a subscription was bought on, as Checkout leaves it.
   */
  private function contract(string $state = 'completed'): Order {
    $item = OrderItem::create(['type' => 'default', 'quantity' => 1]);
    $item->setUnitPrice(new Price('12.50', 'EUR'), TRUE);
    $item->setTitle('Plan S — monthly', TRUE);
    $item->save();

    $order = Order::create([
      'type' => 'default',
      'state' => $state,
      'store_id' => 1,
      'uid' => 1,
      'mail' => 'anna@example.test',
      'order_number' => 'INV-0001',
      'payment_gateway' => 'stripe_subscription',
      'order_items' => [$item],
    ]);
    $order->setData(StripeSubscription::SUBSCRIPTION_KEY, 'sub_test');
    $order->save();

    return $order;
  }

  /**
   * A paid Stripe invoice for one period of this subscription.
   */
  private function invoice(string $id, string $number, int|string $order): Invoice {
    /** @var \Stripe\Invoice $invoice */
    $invoice = Invoice::constructFrom([
      'id' => $id,
      'number' => $number,
      'currency' => 'eur',
      'amount_paid' => 1250,
      'hosted_invoice_url' => 'https://stripe.test/i/' . $id,
      'period_start' => 1_700_000_000,
      'period_end' => 1_702_592_000,
      'status_transitions' => ['paid_at' => 1_700_000_500],
      'lines' => ['data' => [['description' => 'Plan S', 'amount' => 1250, 'quantity' => 1]]],
      'parent' => [
        'subscription_details' => [
          'subscription' => 'sub_test',
          'metadata' => ['order_id' => (string) $order],
        ],
      ],
    ]);

    return $invoice;
  }

}
