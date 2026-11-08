<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_recurring\Kernel;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Stripe\StripeClient;

/**
 * The gateway against real Stripe test mode.
 *
 * Needs STRIPE_TEST_SECRET_KEY, a sandbox's key (`sk_test_` or `rk_test_`);
 * without one it skips, and a key of any other kind refuses to run.
 */
#[Group('stripe_testmode')]
#[RunTestsInSeparateProcesses]
final class StripeTestModeTest extends KernelTestBase {

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
    // Real Stripe: no api base.
    $container->setParameter('commerce_stripe_recurring.api_base', '');
  }

  /**
   * A test clock and a customer on it are made, read and deleted.
   */
  public function testTestClockAndItsCustomerRoundTripThroughStripe(): void {
    $key = (string) getenv('STRIPE_TEST_SECRET_KEY');

    if ($key === '') {
      $this->markTestSkipped('STRIPE_TEST_SECRET_KEY is not set.');
    }

    $this->assertMatchesRegularExpression('/^(sk|rk)_test_/', $key, 'Only a test-mode key may run this group.');

    // As StripeMockGatewayTest: commerce_payment's payments view works out
    // its dependencies through a price formatter, which looks up the default
    // store, so the store's table must exist before that config installs.
    $this->installEntitySchema('user');
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('profile');
    $this->installConfig(['commerce_price', 'commerce_payment']);
    $gateway = PaymentGateway::create([
      'id' => 'stripe',
      'label' => 'Stripe',
      'plugin' => 'stripe_subscription',
      'configuration' => ['display_label' => 'Stripe', 'secret_key' => $key, 'mode' => 'test'],
    ])->getPlugin();
    $this->assertInstanceOf(StripeSubscription::class, $gateway);

    $frozen = time() - 3600;
    $clock = $gateway->createTestClock($frozen, 'commerce-stripe-recurring-test');

    try {
      $this->assertSame($frozen, $gateway->testClockFrozenTime($clock));
      $customer = $gateway->createCustomer('testmode@example.test', ['purpose' => 'commerce-stripe-recurring-test'], $clock);
      $this->assertStringStartsWith('cus_', $customer);
    }
    finally {
      (new StripeClient($key))->testHelpers->testClocks->delete($clock);
    }
  }

}
