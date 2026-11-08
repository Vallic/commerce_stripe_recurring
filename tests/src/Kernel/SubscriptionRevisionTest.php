<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_recurring\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\Store;
use Drupal\commerce_store\Entity\StoreType;
use Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Stripe\StripeObject;

/**
 * What changing an order does to the subscription paying for it.
 *
 * **Every assertion here is about proration.** Stripe charges for a change
 * the moment it is made: an item deleted credits the rest of the period and
 * an item added charges it, so replacing five items with five identical ones
 * nets to nothing on a good day and, on a bad one, puts a refund and a
 * payment on a statement somebody has to explain to a customer.
 *
 * That makes "did the right change get sent" the wrong question. The right
 * one is "was anything sent at all, and was it the narrowest thing that
 * would do" — which is why these tests assert on the *shape* of the update
 * rather than on its effect.
 *
 * This path was verified once by hand against a live subscription, which is
 * not a thing anybody can do twice a day.
 */
#[Group('commerce_stripe_recurring')]
#[CoversClass(StripeSubscription::class)]
#[RunTestsInSeparateProcesses]
final class SubscriptionRevisionTest extends KernelTestBase {

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
    'commerce_stripe_recurring',
  ];

  /**
   * The fake Stripe this test's gateway talks to.
   */
  private FakeStripe $stripe;

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
    $this->installEntitySchema('profile');
    $this->installConfig(['commerce_price']);

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
      'mail' => 'billing@example.test',
      'address' => ['country_code' => 'HR'],
      'default_currency' => 'EUR',
      'billing_countries' => ['HR'],
    ])->save();

    $this->stripe = new FakeStripe();
  }

  /**
   * A subscription that already matches the order is not touched.
   *
   * The most important test here and the least interesting to read. Every
   * save of an order runs through this path, so a revision that decided
   * something had changed when nothing had would prorate a customer on every
   * edit of anything.
   */
  public function testUnchangedOrderSendsNothing(): void {
    $order = $this->order([['Plan S', '10.00', 1]]);

    $this->remote([
      $this->item('si_1', 'Plan S', 1000, 1, signature: $this->signature('Plan S', 1000)),
    ]);

    $this->assertFalse($this->revise($order), 'An unchanged order reported a revision.');
    $this->assertSame(0, $this->stripe->countOf('subscriptions', 'update'), 'An unchanged order was prorated.');
  }

  /**
   * More of the same thing is a quantity change, not a new line.
   *
   * "You added an environment" must reach Stripe as a quantity, because the
   * alternative — delete the item, add an identical one with quantity two —
   * credits the whole period and re-charges it.
   */
  public function testMoreOfTheSameThingChangesTheQuantity(): void {
    $order = $this->order([['Environment', '7.50', 2]]);

    $this->remote([
      $this->item('si_1', 'Environment', 750, 1, signature: $this->signature('Environment', 750)),
    ]);

    $this->assertTrue($this->revise($order));

    $items = $this->sentItems();

    $this->assertCount(1, $items, 'A quantity change sent more than one change.');
    $this->assertSame('si_1', $items[0]['id']);
    $this->assertSame(2, $items[0]['quantity']);
    $this->assertArrayNotHasKey('deleted', $items[0], 'A quantity change deleted the line.');
  }

  /**
   * Two different things at the same price are not read as each other.
   *
   * The reason the name is half of a line's identity. A backup kept three
   * months and an extra environment are both 7.50; matched on amount alone,
   * the first revision swaps their names on the customer's invoice while
   * billing exactly the right total — a bug nobody notices until somebody
   * reads their own invoice.
   */
  public function testTwoThingsAtOnePriceKeepTheirNames(): void {
    $order = $this->order([
      ['Backups', '7.50', 1],
      ['Environment', '7.50', 1],
    ]);

    // As Checkout left them: no metadata of ours, so they match on amount
    // *and* name.
    $this->remote([
      $this->item('si_backups', 'Backups', 750, 1),
      $this->item('si_env', 'Environment', 750, 1),
    ]);

    $this->revise($order);

    foreach ($this->sentItems() as $item) {
      $this->assertArrayNotHasKey('deleted', $item, 'A line was replaced rather than recognized.');
      $this->assertArrayNotHasKey('price', $item, 'A line already on the subscription was added again.');
    }
  }

  /**
   * An item Checkout created is labelled rather than replaced.
   *
   * The first revision is what gives the items a name of ours. Doing it by
   * replacing them would prorate every customer once, on whatever change
   * happened to come first.
   */
  public function testCheckoutsOwnItemsAreLabelledInPlace(): void {
    $order = $this->order([['Plan S', '10.00', 1]]);

    $this->remote([$this->item('si_1', 'Plan S', 1000, 1)]);

    $this->assertTrue($this->revise($order));

    $items = $this->sentItems();

    $this->assertCount(1, $items);
    $this->assertSame('si_1', $items[0]['id']);
    $this->assertSame($this->signature('Plan S', 1000), $items[0]['metadata']['csr_line']);
    $this->assertArrayNotHasKey('deleted', $items[0]);
    $this->assertArrayNotHasKey('quantity', $items[0], 'An unchanged quantity was sent again.');
  }

  /**
   * Something nothing on the order pays for any more is deleted.
   */
  public function testItemNobodyPaysForIsDeleted(): void {
    $order = $this->order([['Plan S', '10.00', 1]]);

    $this->remote([
      $this->item('si_1', 'Plan S', 1000, 1, signature: $this->signature('Plan S', 1000)),
      $this->item('si_gone', 'Backups', 750, 1, signature: $this->signature('Backups', 750)),
    ]);

    $this->assertTrue($this->revise($order));

    $items = $this->sentItems();

    $this->assertCount(1, $items);
    $this->assertSame(['id' => 'si_gone', 'deleted' => TRUE], $items[0]);
  }

  /**
   * Something new gets a price of its own and arrives labelled.
   *
   * A subscription update will not take a price described inline, so the
   * price is made first. The line carries its signature from the moment it
   * exists, so the *next* revision matches it without inferring anything.
   */
  public function testSomethingNewIsPricedAndLabelled(): void {
    $order = $this->order([['Backups', '7.50', 1]]);
    $this->stripe->priceIds = ['price_backups'];

    $this->remote([]);

    $this->assertTrue($this->revise($order));

    $created = $this->stripe->call('prices', 'create');
    $this->assertNotNull($created, 'No price was made for a new line.');
    $this->assertSame(750, $created[0]['unit_amount']);
    $this->assertSame('eur', $created[0]['currency']);
    $this->assertSame('Backups', $created[0]['product_data']['name']);

    $items = $this->sentItems();
    $this->assertSame('price_backups', $items[0]['price']);
    $this->assertSame($this->signature('Backups', 750), $items[0]['metadata']['csr_line']);
  }

  /**
   * A price that changed is a different line: the old goes, the new arrives.
   *
   * Deliberate, and the one case where a delete and an add is right — the
   * customer is credited the rest of the period at the old rate and charged
   * it at the new one, which is what a resize is.
   */
  public function testChangedPriceReplacesTheLine(): void {
    $order = $this->order([['Plan S', '20.00', 1]]);
    $this->stripe->priceIds = ['price_new'];

    $this->remote([
      $this->item('si_1', 'Plan S', 1000, 1, signature: $this->signature('Plan S', 1000)),
    ]);

    $this->assertTrue($this->revise($order));

    $items = $this->sentItems();

    $this->assertCount(2, $items);
    $this->assertSame(['id' => 'si_1', 'deleted' => TRUE], $items[0]);
    $this->assertSame('price_new', $items[1]['price']);
  }

  /**
   * The customer is credited and charged for the part-period, every time.
   *
   * Left to Stripe's default, a change would bill from the next period and
   * nothing for the remainder of this one — which gives away a machine
   * somebody is already running.
   */
  public function testChangesAreProrated(): void {
    $order = $this->order([['Environment', '7.50', 2]]);

    $this->remote([
      $this->item('si_1', 'Environment', 750, 1, signature: $this->signature('Environment', 750)),
    ]);

    $this->revise($order);

    $this->assertSame('create_prorations', $this->stripe->call('subscriptions', 'update')[1]['proration_behavior']);
  }

  /**
   * A Stripe that refuses is never reported as a revision that happened.
   *
   * An order saved here and refused there is a customer billed for something
   * other than what they have, and the only thing worse than failing is
   * appearing to succeed.
   */
  public function testStripeThatRefusesIsNotSilent(): void {
    $order = $this->order([['Environment', '7.50', 2]]);

    $this->remote([
      $this->item('si_1', 'Environment', 750, 1, signature: $this->signature('Environment', 750)),
    ]);

    $this->stripe->failOn = 'subscriptions.update';

    $this->expectException(PaymentGatewayException::class);
    $this->revise($order);
  }

  /**
   * And neither is one that will not describe the subscription.
   */
  public function testSubscriptionThatCannotBeReadIsNotSilent(): void {
    $order = $this->order([['Plan S', '10.00', 1]]);
    $this->stripe->failOn = 'subscriptions.retrieve';

    $this->expectException(PaymentGatewayException::class);
    $this->revise($order);
  }

  /**
   * Nor one that will not price a new line.
   */
  public function testPriceThatCannotBeMadeIsNotSilent(): void {
    $order = $this->order([['Backups', '7.50', 1]]);
    $this->remote([]);
    $this->stripe->failOn = 'prices.create';

    $this->expectException(PaymentGatewayException::class);
    $this->revise($order);
  }

  /**
   * The items sent in the one update this revision made.
   *
   * @return array<int, array<string, mixed>>
   *   The changes.
   */
  private function sentItems(): array {
    $update = $this->stripe->call('subscriptions', 'update');

    $this->assertNotNull($update, 'Nothing was sent to Stripe.');

    return array_values($update[1]['items']);
  }

  /**
   * Puts a subscription with these items behind the fake client.
   *
   * @param array<int, \Stripe\StripeObject> $items
   *   The remote items.
   */
  private function remote(array $items): void {
    $this->stripe->subscription = StripeObject::constructFrom(['items' => ['data' => $items]]);
  }

  /**
   * A Stripe subscription item, as the API describes one.
   */
  private function item(string $id, string $name, int $amount, int $quantity, string $signature = ''): StripeObject {
    return StripeObject::constructFrom([
      'id' => $id,
      'quantity' => $quantity,
      'metadata' => $signature === '' ? [] : ['csr_line' => $signature],
      'price' => [
        'currency' => 'eur',
        'unit_amount' => $amount,
        'recurring' => ['interval' => 'month', 'interval_count' => 1],
        'product' => ['name' => $name],
      ],
    ]);
  }

  /**
   * What the gateway calls a line of this name at this amount.
   *
   * Built the way the gateway builds it rather than copied, so a change to
   * the format shows up as a failure here instead of as silent churn.
   */
  private function signature(string $name, int $amount): string {
    return sprintf('eur:%d:month:1:%s', $amount, $name);
  }

  /**
   * Revises the order through the gateway, against the fake client.
   */
  private function revise(Order $order): bool {
    return $this->gateway()->reviseSubscription($order);
  }

  /**
   * The gateway, built without its plugin machinery and wired to the fake.
   */
  private function gateway(): StripeSubscription {
    $reflection = new \ReflectionClass(StripeSubscription::class);

    /** @var \Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription $plugin */
    $plugin = $reflection->newInstanceWithoutConstructor();

    $reflection->getProperty('minorUnitsConverter')
      ->setValue($plugin, $this->container->get('commerce_price.minor_units_converter'));
    $reflection->getProperty('configuration')
      ->setValue($plugin, ['interval_months' => 1, 'mode' => 'test']);
    $reflection->getProperty('stripe')->setValue($plugin, $this->stripe);

    $reflection->getProperty('conventions')
      ->setValue($plugin, $this->container->get('commerce_stripe_recurring.conventions'));
    $reflection->getProperty('orderLock')
      ->setValue($plugin, $this->container->get('commerce_stripe_recurring.order_lock'));

    return $plugin;
  }

  /**
   * An order of lines, each a title, a gross amount and a quantity.
   *
   * @param array<int, array{string, string, int}> $lines
   *   The lines.
   */
  private function order(array $lines): Order {
    $items = [];

    foreach ($lines as [$title, $amount, $quantity]) {
      $item = OrderItem::create(['type' => 'default', 'quantity' => $quantity]);
      $item->setUnitPrice(new Price($amount, 'EUR'), TRUE);
      $item->setTitle($title, TRUE);
      $item->save();
      $items[] = $item;
    }

    $order = Order::create([
      'type' => 'default',
      'state' => 'completed',
      'store_id' => 1,
      'uid' => 1,
      'order_items' => $items,
    ]);
    $order->setData(StripeSubscription::SUBSCRIPTION_KEY, 'sub_test');
    $order->save();

    return $order;
  }

}
