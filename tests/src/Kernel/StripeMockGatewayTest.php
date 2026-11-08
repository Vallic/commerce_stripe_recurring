<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_recurring\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\Store;
use Drupal\commerce_store\Entity\StoreType;
use Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The gateway's calls to Stripe, answered by stripe-mock (never by Stripe).
 *
 * Stripe-mock validates every request against Stripe's OpenAPI spec, so a
 * parameter Stripe would refuse fails here: that is what these tests are for.
 */
#[Group('commerce_stripe_recurring')]
#[RunTestsInSeparateProcesses]
#[CoversClass(StripeSubscription::class)]
final class StripeMockGatewayTest extends KernelTestBase {

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
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $url = getenv('STRIPE_MOCK_URL');
    $container->setParameter('commerce_stripe_recurring.api_base', is_string($url) && $url !== '' ? rtrim($url, '/') : 'http://stripe-mock:12111');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Skipped only where nobody said where stripe-mock is and the default
    // host does not answer. CI sets STRIPE_MOCK_URL, so a missing service
    // fails there rather than passing quietly.
    if (getenv('STRIPE_MOCK_URL') === FALSE) {
      $socket = @fsockopen('stripe-mock', 12111, $errno, $error, 1.0);
      if ($socket === FALSE) {
        $this->markTestSkipped('stripe-mock is not running: set STRIPE_MOCK_URL.');
      }
      fclose($socket);
    }

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
      'mail' => 'billing@example.test',
      'address' => ['country_code' => 'HR'],
      'default_currency' => 'EUR',
      'billing_countries' => ['HR'],
    ])->save();
  }

  /**
   * The gateway on a key, as the entity API builds it.
   */
  private function gateway(string $key = 'sk_test_gatewayfixture123'): StripeSubscription {
    $entity = PaymentGateway::create([
      'id' => 'stripe',
      'label' => 'Stripe',
      'plugin' => 'stripe_subscription',
      'configuration' => ['display_label' => 'Stripe', 'secret_key' => $key, 'mode' => 'test'],
    ]);
    $plugin = $entity->getPlugin();
    $this->assertInstanceOf(StripeSubscription::class, $plugin);

    return $plugin;
  }

  /**
   * An order of one 19.00 line.
   */
  private function order(): Order {
    $item = OrderItem::create(['type' => 'default', 'quantity' => 1]);
    $item->setUnitPrice(new Price('19.00', 'EUR'), TRUE);
    $item->setTitle('Credits 500', TRUE);
    $item->save();
    $order = Order::create([
      'type' => 'default',
      'store_id' => 1,
      'uid' => 1,
      'mail' => 'anna@example.test',
      'order_items' => [$item],
    ]);
    $order->save();

    return $order;
  }

  /**
   * A subscription checkout and a one-off payment checkout each open a session.
   */
  public function testCheckoutSessionsOpenForSubscriptionsAndPayments(): void {
    $gateway = $this->gateway();
    $order = $this->order();

    $subscription = $gateway->createCheckoutSession($order, 'https://example.test/ok', 'https://example.test/no');
    $this->assertNotSame('', (string) $subscription->id);

    $payment = $gateway->createPaymentCheckoutSession($order, 'https://example.test/ok', 'https://example.test/no');
    $this->assertNotSame('', (string) $payment->id);
    $this->assertSame($payment->id, $order->getData(StripeSubscription::SESSION_KEY), 'The order did not record its session.');
  }

  /**
   * An invoiced subscription, a refund, a customer and a clock all succeed.
   */
  public function testInvoiceTermsRefundsCustomersAndTestClocks(): void {
    $gateway = $this->gateway();
    $order = $this->order();

    $customer = $gateway->createCustomer('anna@example.test', ['account_uuid' => 'uuid-1']);
    $this->assertNotSame('', $customer);

    $subscription = $gateway->createInvoicedSubscription($order, $customer, 14);
    $this->assertSame($subscription, $order->getData(StripeSubscription::SUBSCRIPTION_KEY));

    $this->assertNotSame('', $gateway->refundPayment('pi_123', 500));

    $clock = $gateway->createTestClock(1_790_000_000, 'test-clock');
    $this->assertNotSame('', $clock);
    $this->assertGreaterThan(0, $gateway->testClockFrozenTime($clock));
    $this->assertNotSame('', $gateway->createCustomer('clock@example.test', [], $clock));

    $this->assertSame(1, $gateway->cancelCustomerSubscriptions($customer));
    $this->assertSame(0, $gateway->cancelCustomerSubscriptions(''));
  }

  /**
   * Invoice terms need a customer and a day, and test clocks need a test key.
   */
  public function testRefusalsAreRaisedNotSwallowed(): void {
    $gateway = $this->gateway();

    try {
      $gateway->createInvoicedSubscription($this->order(), '', 14);
      $this->fail('Invoice terms without a customer were started.');
    }
    catch (PaymentGatewayException) {
      $this->addToAssertionCount(1);
    }

    // A live key never asks Stripe for a test clock (and never reaches it).
    $live = $this->gateway('sk_live_gatewayfixture123');
    $this->expectException(PaymentGatewayException::class);
    $live->createTestClock(1_790_000_000);
  }

  /**
   * The events API lists oldest first for the refetch command.
   */
  public function testEventsComeBackOldestFirst(): void {
    $events = $this->gateway()->eventsSince(1_700_000_000);

    $previous = 0;

    foreach ($events as $event) {
      $this->assertGreaterThanOrEqual($previous, (int) $event->created);
      $previous = (int) $event->created;
    }
  }

  /**
   * A customer Stripe does not know has nothing to cancel; closure goes on.
   */
  public function testUnknownCustomerHasNothingToCancel(): void {
    $gateway = $this->gateway();
    $client = new class() extends StripeClient {

      /**
       * {@inheritdoc}
       */
      public function __construct() {
        parent::__construct(['api_key' => 'sk_test_fake']);
      }

      /**
       * {@inheritdoc}
       *
       * @param string $name
       *   The service name.
       */
      public function getService($name): mixed {
        return new class() {

          /**
           * Stripe's answer for an id it never made.
           *
           * @param mixed ...$arguments
           *   Ignored.
           */
          public function all(mixed ...$arguments): never {
            throw InvalidRequestException::factory('No such customer', 404, NULL, NULL, NULL, 'resource_missing');
          }

        };
      }

    };
    $property = new \ReflectionProperty(StripeSubscription::class, 'stripe');
    $property->setValue($gateway, $client);

    $this->assertSame(0, $gateway->cancelCustomerSubscriptions('cus_gone'));
  }

  /**
   * The portal opens with a configuration that offers cards and invoices only.
   *
   * Not Stripe's dashboard default, which also allows cancelling and
   * switching plans: a second path round the site's own revision.
   */
  public function testThePortalOffersCardsAndInvoicesOnly(): void {
    $gateway = $this->gateway();
    $client = new FakePortalStripe();
    (new \ReflectionProperty(StripeSubscription::class, 'stripe'))->setValue($gateway, $client);

    $first = $gateway->billingPortalUrl('cus_1', 'https://example.test/billing');
    $second = $gateway->billingPortalUrl('cus_1', 'https://example.test/billing');
    $this->assertSame(['https://billing.stripe.com/session/test'], array_unique([$first, $second]));

    $calls = $client->calls;
    $kinds = array_column($calls, 0);
    $this->assertSame(['configuration', 'session', 'session'], $kinds, 'One configuration serves every session.');
    $features = $calls[0][1]['features'];
    $this->assertTrue($features['payment_method_update']['enabled']);
    $this->assertTrue($features['invoice_history']['enabled']);
    $this->assertFalse($features['subscription_cancel']['enabled']);
    $this->assertFalse($features['subscription_update']['enabled']);
    $this->assertFalse($features['customer_update']['enabled']);
    $this->assertSame('bpc_test', $calls[1][1]['configuration'], 'The session names the configuration, not the dashboard default.');
  }

}
