<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_recurring\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\Store;
use Drupal\commerce_store\Entity\StoreType;
use Drupal\commerce_stripe_recurring\Conventions;
use Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Stripe\StripeObject;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the gateway asks Stripe for, and what it does with the answer.
 *
 * The checkout a customer is sent to, the return from it, and the calls the
 * platform makes on a running subscription: ending a trial, cancelling now,
 * adding a charge to the next invoice, reading how it is paid, opening the
 * billing portal. Every one of them is a request to a third party with money
 * attached, so the request is asserted as well as the outcome.
 */
#[Group('commerce_stripe_recurring')]
#[RunTestsInSeparateProcesses]
final class StripeGatewayCallsTest extends KernelTestBase {

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
    'commerce_stripe_recurring_test', 'commerce_stripe_recurring',
  ];

  /**
   * The fake Stripe the gateway talks to.
   */
  private FakeStripe $stripe;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    foreach (['user', 'commerce_currency', 'commerce_store', 'commerce_order', 'commerce_order_item', 'profile'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['commerce_price']);

    foreach ([['EUR', 'Euro', '978', '€'], ['USD', 'US dollar', '840', '$']] as [$code, $name, $numeric, $symbol]) {
      Currency::create([
        'currencyCode' => $code,
        'name' => $name,
        'numericCode' => $numeric,
        'symbol' => $symbol,
        'fractionDigits' => 2,
      ])->save();
    }
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

    $this->stripe = new FakeStripe();
  }

  /**
   * The checkout is a subscription of the order's lines, at their prices.
   */
  public function testTheCheckoutIsTheOrderAsSubscription(): void {
    $order = $this->order([['Plan S — monthly', '12.50', 1], ['Extra bandwidth, 1 TB a month', '15.00', 2]]);

    $session = $this->gateway()->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');

    $this->assertSame('cs_test_made', $session->id);
    $sent = $this->sent('checkout.sessions', 'create');
    $this->assertSame('subscription', $sent['mode']);
    $this->assertSame('https://example.test/back', $sent['success_url']);
    $this->assertSame('https://example.test/cancel', $sent['cancel_url']);
    $this->assertSame((string) $order->id(), $sent['client_reference_id']);
    // The order travels in both places a later event can name it from, with
    // what the checkout was opened for.
    $this->assertSame([
      'order_id' => (string) $order->id(),
      'csr_contents' => $this->gateway()->contentsOf($order),
    ], $sent['metadata']);
    $this->assertSame($sent['metadata'], $sent['subscription_data']['metadata']);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sent['metadata']['csr_contents']);
    $this->assertSame('anna@example.test', $sent['customer_email']);
    $this->assertArrayNotHasKey('customer', $sent);
    $this->assertArrayNotHasKey('payment_method_types', $sent, 'Methods were restricted without being asked to be.');

    $this->assertSame([
      [
        'quantity' => 1,
        'price_data' => [
          'currency' => 'eur',
          'unit_amount' => 1250,
          'product_data' => ['name' => 'Plan S — monthly'],
          'recurring' => ['interval' => 'month', 'interval_count' => 1],
        ],
      ],
      [
        'quantity' => 2,
        'price_data' => [
          'currency' => 'eur',
          'unit_amount' => 1500,
          'product_data' => ['name' => 'Extra bandwidth, 1 TB a month'],
          'recurring' => ['interval' => 'month', 'interval_count' => 1],
        ],
      ],
    ], $sent['line_items']);

    // The session is remembered, so the return leg can ask about it.
    $this->assertSame('cs_test_made', (string) $this->reload($order->id())->getData(StripeSubscription::SESSION_KEY));
  }

  /**
   * A period bought ahead is billed at its own interval, within a year.
   *
   * At the line's own price, which is the amount of one charge unless the
   * site says its prices are for one month.
   */
  public function testPeriodBoughtAheadIsBilledAtItsInterval(): void {
    foreach ([3 => 3, 12 => 12, 24 => 12] as $months => $expected) {
      $order = $this->order([['Plan S', '30.00', 1]]);
      // On the order, not on a line: not sent unless prices are monthly.
      $order->addAdjustment($this->discount('-1.50'));
      $order->setData(StripeSubscription::INTERVAL_KEY, $months)->save();

      $this->gateway()->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');

      $price = $this->lastSent('checkout.sessions', 'create')['line_items'][0]['price_data'];
      $this->assertSame($expected, $price['recurring']['interval_count']);
      $this->assertSame(3000, $price['unit_amount']);
    }
  }

  /**
   * Where prices are for one month, a period is billed all of its months.
   */
  public function testMonthlyPricesAreBilledForThePeriod(): void {
    $periods = [1 => [1, 3000], 3 => [3, 9000], 12 => [12, 36000], 24 => [12, 36000]];

    foreach ($periods as $months => [$interval, $amount]) {
      $order = $this->order([['Plan S', '30.00', 1]]);
      $order->setData(StripeSubscription::INTERVAL_KEY, $months)->save();

      $this->gateway(conventions: ['monthly_prices' => TRUE])->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');

      $price = $this->lastSent('checkout.sessions', 'create')['line_items'][0]['price_data'];
      $this->assertSame($interval, $price['recurring']['interval_count']);
      $this->assertSame($amount, $price['unit_amount'], sprintf('%d months of 30.00 were sent as %d.', $months, $price['unit_amount']));
    }

    // The gateway's own months, where the order says none.
    $this->gateway(['interval_months' => 6], conventions: ['monthly_prices' => TRUE])
      ->createCheckoutSession($this->order([['Plan S', '30.00', 1]]), 'https://example.test/back', 'https://example.test/cancel');
    $this->assertSame(18000, $this->lastSent('checkout.sessions', 'create')['line_items'][0]['price_data']['unit_amount']);
  }

  /**
   * A period collects the order's total times its months, discount and all.
   *
   * A discount for paying ahead is on the order, and Stripe is given lines
   * only: each line takes its share.
   */
  public function testThePeriodCollectsTheOrdersTotalTimesItsMonths(): void {
    $monthly = ['monthly_prices' => TRUE];
    // Months, the discount, and what one line of 30.00 a month is sent as.
    $cases = [[3, '-1.50', 8550], [12, '-4.50', 30600], [1, '-1.50', 2850]];

    foreach ($cases as [$months, $discount, $amount]) {
      $order = $this->order([['Plan S', '30.00', 1]]);
      $order->addAdjustment($this->discount($discount));
      $order->setData(StripeSubscription::INTERVAL_KEY, $months)->save();

      $this->gateway(conventions: $monthly)->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');

      $lines = $this->lastSent('checkout.sessions', 'create')['line_items'];
      $this->assertSame($amount, $lines[0]['price_data']['unit_amount']);
      $this->assertSame($this->cents($order) * $months, $this->collected($lines), 'Stripe would not collect the order total times its months.');
    }

    // Several lines, one of them of two units, and a discount that does not
    // divide: 5 % of 42.50 is 2.125, which the order rounds to 2.13. A
    // quarter is three times 40.37, and the cent rounding leaves is on the
    // line of one unit.
    $order = $this->order([['Plan S', '12.50', 1], ['Extra bandwidth', '15.00', 2]]);
    $order->addAdjustment($this->discount('-2.125'));
    $order->setData(StripeSubscription::INTERVAL_KEY, 3)->save();
    $this->assertSame(4037, $this->cents($order));

    $this->gateway(conventions: $monthly)->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');

    $lines = $this->lastSent('checkout.sessions', 'create')['line_items'];
    $this->assertSame([3563, 4274], array_map(static fn (array $line): int => $line['price_data']['unit_amount'], $lines));
    $this->assertSame([1, 2], array_column($lines, 'quantity'));
    $this->assertSame(12111, $this->collected($lines));

    // An adjustment that adds, such as a fee, is shared the same way.
    $order = $this->order([['Plan S', '10.00', 1], ['Backups', '30.00', 1]]);
    $order->addAdjustment($this->discount('4.00'));
    $order->setData(StripeSubscription::INTERVAL_KEY, 3)->save();

    $this->gateway(conventions: $monthly)->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');

    $lines = $this->lastSent('checkout.sessions', 'create')['line_items'];
    $this->assertSame([3300, 9900], array_map(static fn (array $line): int => $line['price_data']['unit_amount'], $lines));
  }

  /**
   * A one-off payment has no months: it takes the order's adjustments only.
   */
  public function testOneOffPaymentOfMonthlyPricesIsNotMultiplied(): void {
    $order = $this->order([['Credits 500', '30.00', 1]]);
    $order->addAdjustment($this->discount('-1.50'));
    $order->setData(StripeSubscription::INTERVAL_KEY, 3)->save();

    $this->gateway(conventions: ['monthly_prices' => TRUE])->createPaymentCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');

    $sent = $this->lastSent('checkout.sessions', 'create');
    $this->assertSame('payment', $sent['mode']);
    $this->assertSame(2850, $sent['line_items'][0]['price_data']['unit_amount']);
    $this->assertArrayNotHasKey('recurring', $sent['line_items'][0]['price_data']);
  }

  /**
   * A discount as large as the order leaves nothing to bill.
   */
  public function testDiscountOfTheWholeOrderIsRefused(): void {
    $order = $this->order([['Plan S', '30.00', 1]]);
    $order->addAdjustment($this->discount('-30.00'));
    $order->save();

    try {
      $this->gateway(conventions: ['monthly_prices' => TRUE])->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');
      $this->fail('An order that comes to nothing was sent to Stripe.');
    }
    catch (PaymentGatewayException $e) {
      $this->assertStringContainsString('nothing on this order that Stripe can bill', $e->getMessage());
    }
    $this->assertSame(0, $this->stripe->countOf('checkout.sessions', 'create'));
  }

  /**
   * A known customer is reused rather than asked for their address again.
   */
  public function testKnownCustomerIsReused(): void {
    $order = $this->order([['Plan S', '12.50', 1]]);
    $order->setData(StripeSubscription::CUSTOMER_KEY, 'cus_known')->save();

    $this->gateway()->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');

    $sent = $this->sent('checkout.sessions', 'create');
    $this->assertSame('cus_known', $sent['customer']);
    $this->assertArrayNotHasKey('customer_email', $sent);
  }

  /**
   * A trial collects a payment method and cancels without one.
   *
   * The order's own trial wins over the gateway's, including a trial of none.
   */
  public function testTrialCollectsMethodAndTheOrdersOwnWins(): void {
    $order = $this->order([['Plan S', '12.50', 1]]);
    $this->gateway(['trial_days' => 3])->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');

    $sent = $this->sent('checkout.sessions', 'create');
    $this->assertSame(3, $sent['subscription_data']['trial_period_days']);
    $this->assertSame(['end_behavior' => ['missing_payment_method' => 'cancel']], $sent['subscription_data']['trial_settings']);
    $this->assertSame('always', $sent['payment_method_collection']);

    $none = $this->order([['Plan S', '12.50', 1]]);
    $none->setData(StripeSubscription::TRIAL_KEY, 0)->save();
    $this->gateway(['trial_days' => 3])->createCheckoutSession($none, 'https://example.test/back', 'https://example.test/cancel');

    $sent = $this->lastSent('checkout.sessions', 'create');
    $this->assertArrayNotHasKey('trial_period_days', $sent['subscription_data'], 'An order sold without a trial was given one.');
    $this->assertArrayNotHasKey('payment_method_collection', $sent);
  }

  /**
   * Restricted methods leave out what the order's currency cannot use.
   */
  public function testRestrictedMethodsFollowTheCurrency(): void {
    $settings = ['restrict_methods' => TRUE, 'instruments' => ['card', 'sepa_debit']];

    $this->gateway($settings)->createCheckoutSession($this->order([['Plan S', '12.50', 1]]), 'https://example.test/back', 'https://example.test/cancel');
    $this->assertSame(['card', 'sepa_debit'], $this->lastSent('checkout.sessions', 'create')['payment_method_types']);

    $this->gateway($settings)->createCheckoutSession($this->order([['Plan S', '15.00', 1]], 'USD'), 'https://example.test/back', 'https://example.test/cancel');
    $this->assertSame(['card'], $this->lastSent('checkout.sessions', 'create')['payment_method_types'], 'SEPA was offered for a dollar subscription.');

    $this->expectException(PaymentGatewayException::class);
    $this->expectExceptionMessage('SEPA Direct Debit is euro only');
    $this->gateway(['restrict_methods' => TRUE, 'instruments' => ['sepa_debit']])
      ->createCheckoutSession($this->order([['Plan S', '15.00', 1]], 'USD'), 'https://example.test/back', 'https://example.test/cancel');
  }

  /**
   * An order with nothing to charge for is never sent.
   */
  public function testAnOrderWithNothingToBillIsRefused(): void {
    try {
      $this->gateway()->createCheckoutSession($this->order([['Basic WAF', '0.00', 1]]), 'https://example.test/back', 'https://example.test/cancel');
      $this->fail('A free order was sent to Stripe.');
    }
    catch (PaymentGatewayException $e) {
      $this->assertStringContainsString('nothing on this order that Stripe can bill', $e->getMessage());
    }
    $this->assertSame(0, $this->stripe->countOf('checkout.sessions', 'create'));
  }

  /**
   * Stripe refusing is a gateway error, and nothing is remembered.
   */
  public function testStripeRefusalIsGatewayError(): void {
    $order = $this->order([['Plan S', '12.50', 1]]);
    $this->stripe->failOn = 'checkout.sessions.create';

    try {
      $this->gateway()->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');
      $this->fail('A refused checkout was not reported.');
    }
    catch (PaymentGatewayException $e) {
      $this->assertStringContainsString('Stripe refused to open a subscription checkout', $e->getMessage());
    }
    $this->assertSame('', (string) $this->reload($order->id())->getData(StripeSubscription::SESSION_KEY));
  }

  /**
   * The return leg records the subscription and the customer, not a payment.
   */
  public function testTheReturnRecordsTheSubscriptionAndCustomer(): void {
    $order = $this->order([['Plan S', '12.50', 1]], subscription: '', state: 'draft');
    $this->gateway()->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');
    $this->stripe->sessionSubscription = 'sub_new';
    $this->stripe->sessionCustomer = 'cus_new';

    $this->gateway()->onReturn($this->reload($order->id()), Request::create('/back'));

    $order = $this->reload($order->id());
    $this->assertSame('sub_new', (string) $order->getData(StripeSubscription::SUBSCRIPTION_KEY));
    $this->assertSame('cus_new', (string) $order->getData(StripeSubscription::CUSTOMER_KEY));
    $this->assertSame(['cs_test_made'], $this->sent('checkout.sessions', 'retrieve', all: TRUE));
  }

  /**
   * A checkout opened for other lines than the order holds records nothing.
   *
   * Commerce places the order once the return leg is through, so the return
   * leg is where an order that changed after its checkout was opened stops.
   *
   * @return array<string, array{string}>
   *   What changed on the order after the checkout was opened.
   */
  public static function changesAfterTheCheckoutOpened(): array {
    return [
      'another line' => ['line'],
      'another quantity' => ['quantity'],
      'another period' => ['period'],
      'another trial' => ['trial'],
      'a checkout from before the comparison' => ['unsaid'],
    ];
  }

  /**
   * Each way the order and its checkout can come to disagree.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('changesAfterTheCheckoutOpened')]
  public function testTheReturnOfCheckoutOpenedForSomethingElseRecordsNothing(string $change): void {
    $order = $this->order([['Plan S', '12.50', 1]], subscription: '', state: 'draft');
    $this->gateway()->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');
    $this->stripe->sessionSubscription = 'sub_x';
    $this->stripe->sessionCustomer = 'cus_x';

    $order = $this->reload($order->id());
    $item = $order->getItems()[0];
    match ($change) {
      'line' => $item->setUnitPrice(new Price('400.00', 'EUR'), TRUE)->setTitle('Plan L', TRUE)->save(),
      'quantity' => $item->setQuantity('3')->save(),
      'period' => $order->setData(StripeSubscription::INTERVAL_KEY, 12),
      'trial' => $order->setData(StripeSubscription::TRIAL_KEY, 30),
      'unsaid' => $this->stripe->sessionMetadata = ['order_id' => (string) $order->id()],
      default => throw new \LogicException('No such change: ' . $change),
    };
    $order->save();

    try {
      $this->gateway()->onReturn($this->reload($order->id()), Request::create('/back'));
      $this->fail('A checkout opened for something else was taken as the order\'s.');
    }
    catch (PaymentGatewayException $e) {
      $this->assertStringContainsString('not opened for what the order now holds', $e->getMessage());
    }

    $order = $this->reload($order->id());
    $this->assertSame('', (string) $order->getData(StripeSubscription::SUBSCRIPTION_KEY));
    $this->assertSame('', (string) $order->getData(StripeSubscription::CUSTOMER_KEY));
  }

  /**
   * An order already placed is not compared: it is revised as it goes.
   */
  public function testTheReturnOfPlacedOrderIsNotCompared(): void {
    $order = $this->order([['Plan S', '12.50', 1]], subscription: '');
    $this->gateway()->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');
    $this->stripe->sessionSubscription = 'sub_new';

    $order = $this->reload($order->id());
    $order->getItems()[0]->setQuantity('2')->save();
    $order->save();

    $this->gateway()->onReturn($this->reload($order->id()), Request::create('/back'));

    $this->assertSame('sub_new', (string) $this->reload($order->id())->getData(StripeSubscription::SUBSCRIPTION_KEY));
  }

  /**
   * One open checkout: opening another closes the one before.
   *
   * And so does the customer coming back without paying. A Stripe that will
   * not close it does not stop the next from opening.
   */
  public function testAnEarlierCheckoutIsClosed(): void {
    $order = $this->order([['Plan S', '12.50', 1]], subscription: '', state: 'draft');

    $this->gateway()->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');
    $this->assertSame(0, $this->stripe->countOf('checkout.sessions', 'expire'), 'There was no earlier checkout to close.');

    $this->gateway()->createCheckoutSession($this->reload($order->id()), 'https://example.test/back', 'https://example.test/cancel');
    $this->assertSame(['cs_test_made'], $this->stripe->call('checkout.sessions', 'expire'));
    $this->assertSame(
      ['create', 'expire', 'create'],
      array_column(array_filter($this->stripe->calls, static fn (array $call): bool => $call[0] === 'checkout.sessions'), 1),
      'The earlier checkout was not closed before the next was opened.',
    );

    $this->gateway()->onCancel($this->reload($order->id()), Request::create('/cancel'));
    $this->assertSame(2, $this->stripe->countOf('checkout.sessions', 'expire'));

    $this->stripe->failOn = 'checkout.sessions.expire';
    $this->stripe->failCode = 'api_error';
    $this->gateway()->onCancel($this->reload($order->id()), Request::create('/cancel'));
    $this->stripe->failOn = NULL;
    $this->gateway()->createPaymentCheckoutSession($this->reload($order->id()), 'https://example.test/back', 'https://example.test/cancel');
    $this->assertSame(4, $this->stripe->countOf('checkout.sessions', 'expire'));
    $this->assertSame(3, $this->stripe->countOf('checkout.sessions', 'create'));
  }

  /**
   * What a checkout was opened for is one value, the same for the same order.
   */
  public function testWhatCheckoutIsOpenedForIsOneValue(): void {
    $order = $this->order([['Plan S', '12.50', 1], ['Backups', '7.50', 1]]);
    $same = $this->order([['Backups', '7.50', 1], ['Plan S', '12.50', 1]]);
    $gateway = $this->gateway();

    $this->assertSame($gateway->contentsOf($order), $gateway->contentsOf($same), 'The order of the lines was read as a difference.');

    // Two lines alike are two lines.
    $this->assertNotSame(
      $gateway->contentsOf($this->order([['Disk', '5.00', 1]])),
      $gateway->contentsOf($this->order([['Disk', '5.00', 1], ['Disk', '5.00', 1]])),
    );
    // The trial, the gateway's own included, and the kind of checkout.
    $this->assertNotSame($gateway->contentsOf($order), $this->gateway(['trial_days' => 3])->contentsOf($order));
    $this->assertNotSame($gateway->contentsOf($order), $gateway->contentsOf($order, FALSE));
    // What the site says its prices are for.
    $quarter = $this->order([['Plan S', '12.50', 1]]);
    $quarter->setData(StripeSubscription::INTERVAL_KEY, 3)->save();
    $monthly = $this->gateway(conventions: ['monthly_prices' => TRUE]);
    $this->assertNotSame($gateway->contentsOf($quarter), $monthly->contentsOf($quarter));
    // An order with nothing to bill has a value no checkout was opened with.
    $free = $this->order([['Basic WAF', '0.00', 1]]);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $gateway->contentsOf($free));

    // A one-off payment says what it was opened for too, on the session and
    // on the payment intent.
    $gateway->createPaymentCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');
    $sent = $this->lastSent('checkout.sessions', 'create');
    $this->assertSame($gateway->contentsOf($order, FALSE), $sent['metadata']['csr_contents']);
    $this->assertSame($sent['metadata'], $sent['payment_intent_data']['metadata']);
    $session = static fn (string $mode, array $metadata): StripeObject => StripeObject::constructFrom([
      'mode' => $mode,
      'metadata' => $metadata,
    ]);
    $this->assertTrue($gateway->openedFor($order, $session('payment', $sent['metadata'])));
    $this->assertFalse($gateway->openedFor($order, $session('subscription', $sent['metadata'])));
    $this->assertFalse($gateway->openedFor($order, $session('payment', [])));
  }

  /**
   * A return the gateway cannot account for is an error, said plainly.
   *
   * @return array<string, array{string, string}>
   *   How the return goes wrong, and what is said.
   */
  public static function badReturns(): array {
    return [
      'never sent' => ['never sent', 'never sent to Stripe'],
      'Stripe will not say' => ['refused', 'would not say what happened'],
      'no subscription made' => ['no subscription', 'without creating a subscription'],
      'left open' => ['open', 'was not completed at Stripe'],
      'expired' => ['expired', 'was not completed at Stripe'],
    ];
  }

  /**
   * Each way the return can go wrong.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('badReturns')]
  public function testBadReturnIsAnError(string $how, string $said): void {
    $order = $this->order([['Plan S', '12.50', 1]]);
    $order->setData(StripeSubscription::SESSION_KEY, 'cs_test_made')->save();
    $this->stripe->sessionSubscription = 'sub_new';
    match ($how) {
      'never sent' => $order->unsetData(StripeSubscription::SESSION_KEY)->save(),
      'refused' => $this->stripe->failOn = 'checkout.sessions.retrieve',
      'no subscription' => $this->stripe->sessionSubscription = NULL,
      'open', 'expired' => $this->stripe->sessionStatus = $how,
      default => throw new \LogicException('No such way to break a return: ' . $how),
    };

    $this->expectException(PaymentGatewayException::class);
    $this->expectExceptionMessage($said);
    $this->gateway()->onReturn($order, Request::create('/back'));
  }

  /**
   * Ending a trial now asks Stripe to charge from today, with no proration.
   */
  public function testEndingTrialNow(): void {
    $order = $this->order([['Plan S', '12.50', 1]], subscription: 'sub_trial');

    $this->assertTrue($this->gateway()->endTrialNow($order));
    $this->assertSame(['sub_trial', ['trial_end' => 'now', 'proration_behavior' => 'none']], $this->stripe->call('subscriptions', 'update'));

    $this->assertFalse($this->gateway()->endTrialNow($this->order([['Plan S', '12.50', 1]], subscription: '')), 'A trial was ended on an order with no subscription.');

    $this->stripe->failOn = 'subscriptions.update';
    $this->expectException(PaymentGatewayException::class);
    $this->gateway()->endTrialNow($order);
  }

  /**
   * Cancelling now ends it without an invoice or a refund.
   *
   * And a subscription Stripe no longer has is already what was asked for.
   */
  public function testCancellingNow(): void {
    $order = $this->order([['Plan S', '12.50', 1]]);

    $this->assertTrue($this->gateway()->cancelSubscriptionNow($order));
    $this->assertSame(['sub_test', ['invoice_now' => FALSE, 'prorate' => FALSE]], $this->stripe->call('subscriptions', 'cancel'));

    $this->stripe->failOn = 'subscriptions.cancel';
    $this->stripe->failCode = 'resource_missing';
    $this->assertTrue($this->gateway()->cancelSubscriptionNow($order), 'A subscription Stripe had already removed could not be cancelled.');

    $this->stripe->failCode = 'api_error';
    $this->expectException(PaymentGatewayException::class);
    $this->gateway()->cancelSubscriptionNow($order);
  }

  /**
   * An order whose subscription was never recorded finds it through checkout.
   */
  public function testTheSubscriptionIsFoundThroughTheCheckout(): void {
    $order = $this->order([['Plan S', '12.50', 1]], subscription: '');
    $order->setData(StripeSubscription::SESSION_KEY, 'cs_test_made')->save();
    $this->stripe->sessionSubscription = 'sub_found';

    $this->assertTrue($this->gateway()->cancelSubscriptionNow($order));
    $this->assertSame('sub_found', $this->stripe->call('subscriptions', 'cancel')[0]);
    $this->assertSame('sub_found', (string) $this->reload($order->id())->getData(StripeSubscription::SUBSCRIPTION_KEY), 'What was found was not written down.');

    // Nothing to find, or Stripe will not say: nothing to cancel.
    $lost = $this->order([['Plan S', '12.50', 1]], subscription: '');
    $lost->setData(StripeSubscription::SESSION_KEY, 'cs_lost')->save();
    $this->stripe->sessionSubscription = NULL;
    $this->assertFalse($this->gateway()->cancelSubscriptionNow($lost));
    $this->stripe->failOn = 'checkout.sessions.retrieve';
    $this->assertFalse($this->gateway()->cancelSubscriptionNow($lost));
  }

  /**
   * Where settings.php names the site, everything the checkout makes says so.
   */
  public function testTheSiteIsNamedOnWhatTheCheckoutMakes(): void {
    $order = $this->order([['Plan S — monthly', '12.50', 1]]);

    $this->gateway(site: 'shop-a')->createCheckoutSession($order, 'https://example.test/back', 'https://example.test/cancel');

    $sent = $this->sent('checkout.sessions', 'create');
    $expected = [
      'order_id' => (string) $order->id(),
      'csr_site' => 'shop-a',
      'csr_contents' => $this->gateway()->contentsOf($order),
    ];
    $this->assertSame($expected, $sent['metadata']);
    $this->assertSame($expected, $sent['subscription_data']['metadata']);
  }

  /**
   * A subscription made before sites were named is named once.
   */
  public function testAnOlderSubscriptionIsNamedOnce(): void {
    $order = $this->order([['Plan S', '12.50', 1]]);
    $this->stripe->subscription = StripeObject::constructFrom([
      'id' => 'sub_test',
      'status' => 'active',
      'metadata' => ['order_id' => (string) $order->id()],
    ]);

    $this->assertTrue($this->gateway(site: 'shop-a')->stampSite($order));
    $this->assertSame(['sub_test', ['metadata' => ['csr_site' => 'shop-a']]], $this->stripe->call('subscriptions', 'update'));
  }

  /**
   * A name already there, another order's subscription or an ended one stay.
   */
  public function testWhatIsNotOursToNameIsLeftAlone(): void {
    $order = $this->order([['Plan S', '12.50', 1]]);
    $subscriptions = [
      'named already' => [
        'status' => 'active',
        'metadata' => ['order_id' => (string) $order->id(), 'csr_site' => 'shop-b'],
      ],
      'another order' => ['status' => 'active', 'metadata' => ['order_id' => '999']],
      'ended' => ['status' => 'canceled', 'metadata' => ['order_id' => (string) $order->id()]],
    ];

    foreach ($subscriptions as $case => $subscription) {
      $this->stripe->subscription = StripeObject::constructFrom(['id' => 'sub_test'] + $subscription);
      $this->assertFalse($this->gateway(site: 'shop-a')->stampSite($order), $case);
    }
    $this->assertFalse($this->gateway()->stampSite($order), 'No site is named.');
    $this->assertSame(0, $this->stripe->countOf('subscriptions', 'update'));
  }

  /**
   * A charge is added to the next invoice in cents, once per key.
   */
  public function testChargeIsAddedToTheNextInvoice(): void {
    $order = $this->order([['Plan S', '12.50', 1]]);
    $order->setData(StripeSubscription::CUSTOMER_KEY, 'cus_known')->save();

    $this->assertTrue($this->gateway()->addToNextInvoice($order, new Price('2.78', 'EUR'), 'Autoscale, 3 October', 'autoscale-42-2026-10-03'));

    $this->assertSame([
      [
        'customer' => 'cus_known',
        'subscription' => 'sub_test',
        'amount' => 278,
        'currency' => 'eur',
        'description' => 'Autoscale, 3 October',
      ],
      ['idempotency_key' => 'autoscale-42-2026-10-03'],
    ], $this->stripe->call('invoiceItems', 'create'));

    // Without a customer there is nobody to bill.
    $this->assertFalse($this->gateway()->addToNextInvoice($this->order([['Plan S', '12.50', 1]]), new Price('1.00', 'EUR'), 'x', 'k'));

    $this->stripe->failOn = 'invoiceItems.create';
    $this->expectException(PaymentGatewayException::class);
    $this->gateway()->addToNextInvoice($order, new Price('2.78', 'EUR'), 'Autoscale, 3 October', 'autoscale-42-2026-10-03');
  }

  /**
   * How a subscription is paid, as the method's type and its fingerprint.
   */
  public function testThePaymentFingerprint(): void {
    $order = $this->order([['Plan S', '12.50', 1]]);

    $this->stripe->subscription = StripeObject::constructFrom([
      'default_payment_method' => ['type' => 'card', 'card' => ['fingerprint' => 'fp_abc']],
    ]);
    $this->assertSame('card:fp_abc', $this->gateway()->paymentFingerprint($order));
    $this->assertSame(['sub_test', ['expand' => ['default_payment_method']]], $this->stripe->call('subscriptions', 'retrieve'));

    $this->stripe->subscription = StripeObject::constructFrom([
      'default_payment_method' => ['type' => 'sepa_debit', 'sepa_debit' => ['fingerprint' => 'fp_iban']],
    ]);
    $this->assertSame('sepa_debit:fp_iban', $this->gateway()->paymentFingerprint($order));

    // No method, or one without a fingerprint, is no answer.
    $this->stripe->subscription = StripeObject::constructFrom(['default_payment_method' => NULL]);
    $this->assertNull($this->gateway()->paymentFingerprint($order));
    $this->stripe->subscription = StripeObject::constructFrom([
      'default_payment_method' => ['type' => 'link', 'link' => []],
    ]);
    $this->assertNull($this->gateway()->paymentFingerprint($order));
    $this->assertNull($this->gateway()->paymentFingerprint($this->order([['Plan S', '12.50', 1]], subscription: '')));

    $this->stripe->failOn = 'subscriptions.retrieve';
    $this->expectException(PaymentGatewayException::class);
    $this->gateway()->paymentFingerprint($order);
  }

  /**
   * The dashboard's portal opens for a customer and returns where it left.
   *
   * No configuration is named, so Stripe uses the account's default.
   */
  public function testTheBillingPortal(): void {
    $this->assertSame('https://billing.stripe.test/p/session', $this->gateway(['portal' => 'dashboard'])->billingPortalUrl('cus_known', 'https://example.test/billing'));
    $this->assertSame([['customer' => 'cus_known', 'return_url' => 'https://example.test/billing']], $this->stripe->call('billingPortal.sessions', 'create'));

    $this->assertNull($this->gateway(['portal' => 'dashboard'])->billingPortalUrl('  ', 'https://example.test/billing'), 'A portal was opened for nobody.');

    $this->stripe->failOn = 'billingPortal.sessions.create';
    $this->expectException(PaymentGatewayException::class);
    $this->gateway(['portal' => 'dashboard'])->billingPortalUrl('cus_known', 'https://example.test/billing');
  }

  /**
   * The settings form keeps what it is given, trimmed and bounded.
   */
  public function testTheSettingsAreKeptTrimmedAndBounded(): void {
    /** @var \Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription $plugin */
    $plugin = $this->container->get('plugin.manager.commerce_payment_gateway')->createInstance('stripe_subscription', ['mode' => 'test']);
    $form = $plugin->buildConfigurationForm(['#parents' => []], new FormState());
    // Not required: a site may supply the keys from its settings instead
    // (StripeCredentialsInterface), and the webhook refuses every event
    // while the gateway has no usable secret.
    $this->assertEmpty($form['webhook_secret']['#required'] ?? FALSE);
    $this->assertSame('payment_methods', $form['portal']['#default_value'], 'The portal does not default to the restricted one.');

    $state = new FormState();
    $state->setValues([
      'secret_key' => ' sk_test_x ',
      'publishable_key' => 'pk_test_x',
      'webhook_secret' => ' whsec_x',
      'restrict_methods' => 1,
      'instruments' => ' card, ,sepa_debit ',
      'interval_months' => 40,
      'trial_days' => '5',
      'portal' => 'dashboard',
      'mode' => 'test',
      'display_label' => 'Card or direct debit',
      'payment_method_types' => ['stripe_subscription' => 'stripe_subscription'],
      'collect_billing_information' => FALSE,
    ]);
    $plugin->submitConfigurationForm($form, $state);

    $settings = $plugin->getConfiguration();
    $this->assertSame('sk_test_x', $settings['secret_key']);
    $this->assertSame('whsec_x', $settings['webhook_secret']);
    $this->assertTrue($settings['restrict_methods']);
    $this->assertSame(['card', 'sepa_debit'], $settings['instruments']);
    $this->assertSame(12, $settings['interval_months'], 'A charge interval past a year was kept.');
    $this->assertSame(5, $settings['trial_days']);
    $this->assertSame('dashboard', $settings['portal']);

    // Anything else is the restricted portal.
    $state->setValue('portal', 'anything');
    $plugin->submitConfigurationForm($form, $state);
    $this->assertSame('payment_methods', $plugin->getConfiguration()['portal']);
  }

  /**
   * What the gateway sent for a call, the first time.
   *
   * @return mixed
   *   The first argument, or every argument when $all is set.
   */
  private function sent(string $service, string $method, bool $all = FALSE): mixed {
    $arguments = $this->stripe->call($service, $method);
    $this->assertNotNull($arguments, sprintf('%s.%s was never called.', $service, $method));

    return $all ? $arguments : $arguments[0];
  }

  /**
   * What the gateway sent for a call, the last time.
   *
   * @return array<string, mixed>
   *   The first argument of the last call.
   */
  private function lastSent(string $service, string $method): array {
    $calls = array_values(array_filter($this->stripe->calls, static fn (array $call): bool => $call[0] === $service && $call[1] === $method));
    $this->assertNotEmpty($calls);

    return end($calls)[2][0];
  }

  /**
   * The gateway, built without its plugin machinery and wired to the fake.
   *
   * @param array<string, mixed> $settings
   *   Settings over the defaults.
   * @param string $site
   *   The site settings.php names, or '' for none.
   * @param array<string, mixed> $conventions
   *   The site's conventions. With neither these nor a site, the container's.
   */
  private function gateway(array $settings = [], string $site = '', array $conventions = []): StripeSubscription {
    $reflection = new \ReflectionClass(StripeSubscription::class);

    /** @var \Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription $plugin */
    $plugin = $reflection->newInstanceWithoutConstructor();
    // Its own definition, which the defaults are read from.
    $reflection->getProperty('pluginId')->setValue($plugin, 'stripe_subscription');
    $reflection->getProperty('pluginDefinition')->setValue($plugin, $this->container->get('plugin.manager.commerce_payment_gateway')->getDefinition('stripe_subscription'));
    $reflection->getProperty('minorUnitsConverter')->setValue($plugin, $this->container->get('commerce_price.minor_units_converter'));
    $defaults = $plugin->defaultConfiguration() + ['interval_months' => 1, 'mode' => 'test'];
    $reflection->getProperty('configuration')->setValue($plugin, $settings + $defaults);
    $reflection->getProperty('stripe')->setValue($plugin, $this->stripe);
    $reflection->getProperty('stringTranslation')->setValue($plugin, $this->container->get('string_translation'));
    $reflection->getProperty('orderLock')->setValue($plugin, $this->container->get('commerce_stripe_recurring.order_lock'));
    $reflection->getProperty('conventions')->setValue($plugin, $site === '' && $conventions === [] ? $this->container->get('commerce_stripe_recurring.conventions') : new Conventions($conventions, $site));
    $reflection->getProperty('credentials')->setValue($plugin, $this->container->get('commerce_stripe_recurring.credentials'));
    $reflection->getProperty('state')->setValue($plugin, $this->container->get('state'));

    return $plugin;
  }

  /**
   * An order of lines, each a title, a price and a quantity.
   *
   * @param array<int, array{string, string, int}> $lines
   *   The lines.
   * @param string $currency
   *   The currency every line is in.
   * @param string $subscription
   *   The subscription paying for it, or nothing.
   * @param string $state
   *   The order's state: `draft` for one still in checkout.
   */
  private function order(array $lines, string $currency = 'EUR', string $subscription = 'sub_test', string $state = 'completed'): Order {
    $items = [];
    foreach ($lines as [$title, $amount, $quantity]) {
      $item = OrderItem::create(['type' => 'default', 'quantity' => $quantity]);
      $item->setUnitPrice(new Price($amount, $currency), TRUE);
      $item->setTitle($title, TRUE);
      $item->save();
      $items[] = $item;
    }

    $order = Order::create([
      'type' => 'default',
      'state' => $state,
      'store_id' => 1,
      'uid' => 1,
      'mail' => 'anna@example.test',
      'order_items' => $items,
    ]);
    if ($subscription !== '') {
      $order->setData(StripeSubscription::SUBSCRIPTION_KEY, $subscription);
    }
    $order->save();

    return $order;
  }

  /**
   * An adjustment on the order itself, not on a line.
   */
  private function discount(string $amount, string $currency = 'EUR'): Adjustment {
    return new Adjustment([
      'type' => 'custom',
      'label' => 'Paid ahead',
      'amount' => new Price($amount, $currency),
    ]);
  }

  /**
   * The order's total, in cents.
   */
  private function cents(Order $order): int {
    $total = $this->reload($order->id())->getTotalPrice();
    $this->assertNotNull($total);

    return $this->container->get('commerce_price.minor_units_converter')->toMinorUnits($total);
  }

  /**
   * What Stripe would collect for a period of these lines, in cents.
   *
   * @param array<int, array<string, mixed>> $lines
   *   Line items as sent.
   */
  private function collected(array $lines): int {
    return array_sum(array_map(static fn (array $line): int => $line['quantity'] * $line['price_data']['unit_amount'], $lines));
  }

  /**
   * An order as stored.
   */
  private function reload(int|string $id): Order {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_order');
    $storage->resetCache([$id]);
    $order = $storage->load($id);
    $this->assertInstanceOf(Order::class, $order);

    return $order;
  }

}
