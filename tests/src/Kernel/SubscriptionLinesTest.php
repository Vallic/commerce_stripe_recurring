<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_recurring\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\Store;
use Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription;
use Drupal\commerce_store\Entity\StoreType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What this gateway asks Stripe to bill.
 *
 * The module had no tests at all, which is how it came to be billing net
 * amounts on a store whose prices are net and whose tax arrives as an
 * adjustment: every customer was charged their subtotal and no VAT, silently,
 * on every renewal, while the Commerce invoice said the right number the whole
 * time.
 *
 * Nothing here talks to Stripe. What is checked is the line items the gateway
 * builds out of an order, which is the half the bug was in.
 */
#[Group('commerce_stripe_recurring')]
#[RunTestsInSeparateProcesses]
final class SubscriptionLinesTest extends KernelTestBase {

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

    // The currency the prices are in. Imported rather than assumed: a Price
    // cannot be built without it, which is the first thing every one of these
    // tests does.
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
  }

  /**
   * A line is billed at what the customer owes, tax included.
   *
   * The whole of the bug this test exists for: prices on this platform's
   * store are net and VAT arrives as an adjustment, so billing the unit price
   * charges the subtotal and collects nothing. On an order of 37.87 that is
   * 9.47 nobody ever asked Stripe for.
   */
  public function testLinesCarryTheTax(): void {
    $order = $this->order('10.00', '2.50');
    $lines = $this->lineItems($order);

    $this->assertCount(1, $lines);
    $this->assertSame(1250, $lines[0]['price_data']['unit_amount'], 'The tax did not reach Stripe.');
    $this->assertSame('eur', $lines[0]['price_data']['currency']);
    $this->assertSame('month', $lines[0]['price_data']['recurring']['interval']);
  }

  /**
   * An order of nothing but zero lines is refused rather than sent empty.
   *
   * A line at nothing is real — a machine an environment add-on pays for is
   * on the order at zero so the invoice names it — and Stripe refuses a
   * recurring price of zero, so such lines are left off. An order that is
   * *only* those has nothing to bill, and saying so is better than opening a
   * subscription that charges nobody anything.
   */
  public function testAnOrderWithNothingToBillIsRefused(): void {
    $order = $this->order('0', '0');

    $this->expectException(PaymentGatewayException::class);
    $this->lineItems($order);
  }

  /**
   * An order nobody handed to this gateway has nothing to revise.
   *
   * Not an error: an order billed by bank transfer is changed by changing the
   * order, and there is nothing at the far end to tell.
   */
  public function testRevisingAnUnbilledOrderIsNotAnError(): void {
    $order = $this->order('10.00', '2.50');

    $this->assertFalse($this->gateway()->reviseSubscription($order));
  }

  /**
   * The line items the gateway would send for an order.
   *
   * @return array<int, array<string, mixed>>
   *   Stripe line items.
   */
  private function lineItems(Order $order): array {
    $plugin = $this->gateway();
    $method = new \ReflectionMethod($plugin, 'lineItems');

    return array_values($method->invoke($plugin, $order));
  }

  /**
   * The gateway under test, built without its plugin machinery.
   *
   * A payment gateway plugin resolves a payment type and a payment method
   * type as it is constructed, and those drag in schemas, entity types and
   * config that have nothing to do with the two things under test: what this
   * gateway makes of an order's lines, and what it does with an order no
   * gateway is billing.
   *
   * So the object is made without its constructor and given the one
   * collaborator those paths use. It is a narrow test of a narrow thing, and
   * it is the difference between covering the tax bug and covering nothing.
   */
  private function gateway(): StripeSubscription {
    $reflection = new \ReflectionClass(StripeSubscription::class);

    /** @var \Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription $plugin */
    $plugin = $reflection->newInstanceWithoutConstructor();

    $converter = $reflection->getProperty('minorUnitsConverter');
    $converter->setValue($plugin, $this->container->get('commerce_price.minor_units_converter'));

    $configuration = $reflection->getProperty('configuration');
    $configuration->setValue($plugin, ['interval_months' => 1, 'mode' => 'test']);

    $reflection->getProperty('conventions')
      ->setValue($plugin, $this->container->get('commerce_stripe_recurring.conventions'));
    $reflection->getProperty('orderLock')
      ->setValue($plugin, $this->container->get('commerce_stripe_recurring.order_lock'));

    return $plugin;
  }

  /**
   * An order of one line, net, with tax as an adjustment on it.
   */
  private function order(string $net, string $tax): Order {
    StoreType::create(['id' => 'online', 'label' => 'Online'])->save();
    $store = Store::create([
      'type' => 'online',
      'uid' => 1,
      'name' => 'Example store',
      'mail' => 'billing@example.test',
      'address' => ['country_code' => 'HR'],
      'default_currency' => 'EUR',
      'billing_countries' => ['HR'],
    ]);
    $store->save();

    $item = OrderItem::create(['type' => 'default', 'quantity' => 1]);
    $item->setUnitPrice(new Price($net, 'EUR'), TRUE);
    $item->setTitle('Plan S - monthly', TRUE);

    if ($tax !== '0') {
      $item->addAdjustment(new Adjustment([
        'type' => 'tax',
        'label' => 'VAT',
        'amount' => new Price($tax, 'EUR'),
        'included' => FALSE,
      ]));
    }

    $item->save();

    $order = Order::create([
      'type' => 'default',
      'state' => 'completed',
      'store_id' => $store->id(),
      'uid' => 1,
      'order_items' => [$item],
    ]);
    $order->save();

    return $order;
  }

}
