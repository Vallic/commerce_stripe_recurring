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

/**
 * What serving notice on a subscription sends to Stripe.
 *
 * **The date is this platform's, and Stripe is told it rather than asked
 * for it.** That is the whole of the argument here. `cancel_at_period_end`
 * would let Stripe decide when the subscription stops, and Stripe's answer
 * is always the end of the period being collected — which is right for
 * somebody cancelling in the middle of a month and wrong for somebody
 * cancelling inside the notice window, whose subscription runs to the end
 * of the period *after* this one because that is what they agreed to and
 * what the site quoted them.
 *
 * A flag can only express one of those two answers, so these tests assert
 * that a timestamp is sent and that it arrives unrounded.
 *
 * The second argument is that cancellation must not prorate. Stripe charges
 * for changes to a subscription's items the moment they are made, so a
 * cancellation that touched the items would put a credit on the statement
 * of somebody who has just been told their billing stops at a date. Nothing
 * here may send anything but the date.
 *
 * Exercised against the fake client rather than a live subscription. The
 * refusal path was already covered from `SubscriptionCancellation`'s side —
 * that a refusal records nothing — and this covers the side that talks to
 * Stripe, which had no coverage at all. What is still not proved is that
 * Stripe accepts this shape: that wants one real cancellation in test mode,
 * which is on the list.
 */
#[Group('commerce_stripe_recurring')]
#[CoversClass(StripeSubscription::class)]
#[RunTestsInSeparateProcesses]
final class SubscriptionCancellationTest extends KernelTestBase {

  /**
   * A date with nothing round about it, so rounding would show.
   *
   * 2027-03-14 09:26:53 UTC. Not a period boundary, not midnight, not the
   * end of a month: if anything on the way through normalized the date, the
   * assertion that it arrived intact is what fails.
   */
  private const int NOTICE_DATE = 1804325213;

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
   * The subscription is told to stop, and told which one it is.
   *
   * One update, addressed to the id the order carries. The id matters as
   * much as the date: the gateway holds no subscription of its own, so an
   * order that reached for the wrong key would cancel somebody else's.
   */
  public function testTheSubscriptionOnTheOrderIsTheOneCancelled(): void {
    $order = $this->order();

    $this->assertTrue($this->cancel($order, self::NOTICE_DATE), 'A cancellation that reached Stripe reported failure.');

    $update = $this->stripe->call('subscriptions', 'update');
    $this->assertNotNull($update, 'Nothing was sent to Stripe.');
    $this->assertSame('sub_test', $update[0], 'A different subscription was cancelled.');
    $this->assertSame(1, $this->stripe->countOf('subscriptions', 'update'), 'The subscription was updated more than once.');
  }

  /**
   * The date is sent as a date, not as a flag.
   *
   * `cancel_at` carries the day this platform worked out from the notice the
   * customer agreed to. `cancel_at_period_end` would throw that away and let
   * Stripe substitute the end of the period it happens to be collecting,
   * which is a different day for everybody who gave notice inside the window.
   */
  public function testTheDateIsSentRatherThanAnEndOfPeriodFlag(): void {
    $order = $this->order();

    $this->cancel($order, self::NOTICE_DATE);

    $sent = $this->stripe->call('subscriptions', 'update')[1];

    $this->assertSame(['cancel_at' => self::NOTICE_DATE], $sent, 'The cancellation sent something other than the date.');
    $this->assertArrayNotHasKey('cancel_at_period_end', $sent, 'Stripe was left to choose the date.');
  }

  /**
   * The date arrives as it was given, to the second.
   *
   * Nothing on the way rounds to midnight or to a period boundary. A
   * cancellation quoted to the customer for one day and sent to Stripe for
   * another is a bill they were told they would not get.
   */
  public function testTheDateIsNotRounded(): void {
    $order = $this->order();

    $this->cancel($order, self::NOTICE_DATE);

    $this->assertSame(
      self::NOTICE_DATE,
      $this->stripe->call('subscriptions', 'update')[1]['cancel_at'],
      'The cancellation date was moved on its way to Stripe.',
    );
  }

  /**
   * A date beyond this period is sent as given, not pulled back.
   *
   * The notice-window case, which is the reason the flag is not used. This
   * date is a year out; a gateway that had quietly substituted the current
   * period's end would stop collecting eleven months early.
   */
  public function testNoticeBeyondThisPeriodKeepsItsOwnDate(): void {
    $order = $this->order();
    $distant = self::NOTICE_DATE + (365 * 86400);

    $this->cancel($order, $distant);

    $this->assertSame(
      $distant,
      $this->stripe->call('subscriptions', 'update')[1]['cancel_at'],
      'A cancellation past this period was pulled back to it.',
    );
  }

  /**
   * Cancelling does not touch the items, so nothing is prorated.
   *
   * Stripe charges for an item change when it is made. A cancellation that
   * deleted the items would credit the unused part of the period and put a
   * refund on the statement of somebody who has been told only that their
   * billing ends on a date.
   */
  public function testCancellingDoesNotProrateTheItems(): void {
    $order = $this->order();

    $this->cancel($order, self::NOTICE_DATE);

    $this->assertSame(0, $this->stripe->countOf('prices', 'create'), 'A cancellation created a price.');
    $this->assertSame(0, $this->stripe->countOf('subscriptions', 'retrieve'), 'A cancellation read the items it does not change.');
    $this->assertArrayNotHasKey('items', $this->stripe->call('subscriptions', 'update')[1], 'A cancellation changed the items.');
  }

  /**
   * An order Stripe never billed is not an error, and sends nothing.
   *
   * Checkout abandoned, or paid some other way. There is nothing at the far
   * end to stop, and reaching for it would be an API call against an empty
   * id — so it answers FALSE and the caller records the cancellation on this
   * side alone.
   */
  public function testAnOrderWithNoSubscriptionSendsNothing(): void {
    $order = $this->order(subscription: '');

    $this->assertFalse($this->cancel($order, self::NOTICE_DATE), 'An order with no subscription claimed to cancel one.');
    $this->assertSame([], $this->stripe->calls, 'An order with no subscription still called Stripe.');
  }

  /**
   * A refusal is raised, never swallowed.
   *
   * The caller records nothing when this throws, which is the arrangement
   * that stops a customer being told their subscription stopped while it
   * goes on collecting. A FALSE here instead of an exception would be read
   * as "nothing to cancel" and the cancellation would be recorded anyway.
   */
  public function testStripeRefusingIsRaised(): void {
    $order = $this->order();
    $this->stripe->failOn = 'subscriptions.update';

    $this->expectException(PaymentGatewayException::class);

    $this->cancel($order, self::NOTICE_DATE);
  }

  /**
   * The refusal names the subscription that is still running.
   *
   * Whoever reads this has to go and stop it by hand in Stripe's dashboard,
   * and an error that does not say which subscription leaves them searching.
   */
  public function testTheRefusalNamesTheSubscription(): void {
    $order = $this->order();
    $this->stripe->failOn = 'subscriptions.update';

    try {
      $this->cancel($order, self::NOTICE_DATE);
      $this->fail('Stripe refused and the gateway reported success.');
    }
    catch (PaymentGatewayException $e) {
      $this->assertStringContainsString('sub_test', $e->getMessage(), 'The refusal did not name the subscription.');
      $this->assertStringContainsString('Stripe said no', $e->getMessage(), 'The refusal dropped what Stripe actually said.');
    }
  }

  /**
   * Cancels the order through the gateway, against the fake client.
   */
  private function cancel(Order $order, int $timestamp): bool {
    return $this->gateway()->cancelSubscriptionAt($order, $timestamp);
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
   * A completed order carrying a Stripe subscription.
   */
  private function order(string $subscription = 'sub_test'): Order {
    $item = OrderItem::create(['type' => 'default', 'quantity' => 1]);
    $item->setUnitPrice(new Price('10.00', 'EUR'), TRUE);
    $item->setTitle('Plan S', TRUE);
    $item->save();

    $order = Order::create([
      'type' => 'default',
      'state' => 'completed',
      'store_id' => 1,
      'uid' => 1,
      'order_items' => [$item],
    ]);

    if ($subscription !== '') {
      $order->setData(StripeSubscription::SUBSCRIPTION_KEY, $subscription);
    }

    $order->save();

    return $order;
  }

}
