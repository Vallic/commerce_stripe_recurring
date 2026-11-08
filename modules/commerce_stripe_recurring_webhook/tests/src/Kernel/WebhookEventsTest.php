<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_recurring_webhook\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\Store;
use Drupal\commerce_store\Entity\StoreType;
use Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription;
use Drupal\commerce_stripe_recurring\RenewalOrders;
use Drupal\commerce_stripe_recurring_webhook\Controller\WebhookController;
use Drupal\commerce_stripe_recurring_webhook\Event\WebhookEvent;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the Stripe endpoint does with a signed event, and with an unsigned one.
 *
 * Everything that reaches the contract from Stripe comes through here: the
 * checkout finishing, each invoice being paid or failing, the subscription
 * ending, and any other event a site's subscriber acts on. Each is sent as
 * Stripe sends it — a JSON body signed with the endpoint's secret — so the
 * signature check, the dispatch and the handler are tested together.
 */
#[Group('commerce_stripe_recurring')]
#[RunTestsInSeparateProcesses]
final class WebhookEventsTest extends KernelTestBase {

  /**
   * The endpoint's signing secret.
   */
  private const string SECRET = 'whsec_test_secret';

  /**
   * What a test subscriber saw: each event's type, gateway and handled flag.
   *
   * @var list<array{string, string|int|null, bool}>
   */
  private array $seen = [];

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
    'commerce_stripe_recurring_webhook',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $types = [
      'user', 'commerce_currency', 'commerce_store', 'commerce_order',
      'commerce_order_item', 'commerce_payment', 'profile',
    ];
    foreach ($types as $type) {
      $this->installEntitySchema($type);
    }
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

    $this->gateway(self::SECRET);
  }

  /**
   * A gateway that is not Stripe's has nothing to verify an event with.
   */
  public function testGatewayThatIsNotStripeIsRefused(): void {
    $manual = PaymentGateway::create([
      'id' => 'manual',
      'label' => 'Manual',
      'plugin' => 'manual',
      'configuration' => ['display_label' => 'Manual', 'instructions' => ['value' => '', 'format' => 'plain_text']],
    ]);
    $manual->save();

    $response = $this->post(['type' => 'invoice.paid', 'data' => ['object' => []]], gateway: $manual);

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('Not a Stripe subscription gateway.', $this->body($response)['error']);
  }

  /**
   * No secret, or a placeholder in its place, refuses every event.
   *
   * Refused as unconfigured rather than as a bad signature, which would send
   * somebody to the Stripe dashboard hunting for a mismatch that is not there.
   */
  public function testAnEndpointWithNoSigningSecretRefusesEverything(): void {
    foreach (['', 'changeme', 'N/A'] as $held) {
      $gateway = $this->gateway($held);
      $response = $this->post($this->checkout(1), gateway: $gateway);

      $this->assertSame(400, $response->getStatusCode(), sprintf('A gateway holding "%s" accepted an event.', $held));
      $this->assertSame('No signing secret configured.', $this->body($response)['error']);
    }
  }

  /**
   * An event signed with anything but the endpoint's secret is refused.
   */
  public function testBadSignatureIsRefused(): void {
    $order = $this->contract();

    $forged = $this->post($this->checkout((int) $order->id()), signWith: 'whsec_somebody_else');
    $this->assertSame(400, $forged->getStatusCode());
    $this->assertSame('Bad signature.', $this->body($forged)['error']);

    $unsigned = $this->post($this->checkout((int) $order->id()), signature: '');
    $this->assertSame(400, $unsigned->getStatusCode());

    // Nothing was written from either.
    $this->assertSame('sub_existing', (string) $this->reload($order->id())->getData(StripeSubscription::SUBSCRIPTION_KEY));
  }

  /**
   * A checkout that finished records the subscription and the customer.
   *
   * For the customer who paid and closed the tab before the return leg, which
   * is where these are normally written.
   */
  public function testCheckoutCompletedRecordsTheSubscriptionAndCustomer(): void {
    $order = $this->contract(subscription: NULL);

    $response = $this->post($this->checkout((int) $order->id()));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue($this->body($response)['handled']);
    $order = $this->reload($order->id());
    $this->assertSame('sub_new', (string) $order->getData(StripeSubscription::SUBSCRIPTION_KEY));
    $this->assertSame('cus_new', (string) $order->getData(StripeSubscription::CUSTOMER_KEY));

    // Delivered again, nothing is left to record, and nothing is saved.
    $this->assertFalse($this->body($this->post($this->checkout((int) $order->id())))['handled']);
  }

  /**
   * A checkout whose subscription is already recorded is not written over.
   */
  public function testCheckoutCompletedDoesNotOverwriteWhatTheReturnLegWrote(): void {
    $order = $this->contract(subscription: 'sub_existing');
    $order->setData(StripeSubscription::CUSTOMER_KEY, 'cus_existing')->save();

    $this->assertFalse($this->body($this->post($this->checkout((int) $order->id())))['handled']);

    $order = $this->reload($order->id());
    $this->assertSame('sub_existing', (string) $order->getData(StripeSubscription::SUBSCRIPTION_KEY));
    $this->assertSame('cus_existing', (string) $order->getData(StripeSubscription::CUSTOMER_KEY));
  }

  /**
   * An event naming no order, or not a number, changes nothing.
   */
  public function testAnEventWithNoOrderOfOursIsIgnored(): void {
    foreach ([NULL, 'abc', '12; DROP', '999999'] as $reference) {
      $event = $this->checkout(0);
      $event['data']['object']['client_reference_id'] = $reference;

      $response = $this->post($event);

      $this->assertSame(200, $response->getStatusCode(), 'Stripe would retry this for ever.');
      $this->assertFalse($this->body($response)['handled']);
    }
  }

  /**
   * A paid invoice, through the endpoint, settles the order it names.
   */
  public function testInvoicePaidSettlesTheOrder(): void {
    $order = $this->contract();

    $response = $this->post($this->event('invoice.paid', $this->invoice('in_first', (int) $order->id())));

    $this->assertTrue($this->body($response)['handled']);
    $order = $this->reload($order->id());
    $this->assertSame('in_first', (string) $order->getData(RenewalOrders::INVOICE_KEY));
    $this->assertSame(12.5, (float) $order->getTotalPaid()->getNumber());
  }

  /**
   * A signed event typed as an invoice that is not one still places a draft.
   *
   * The event is authentic; a paid order left in draft is worse than one left
   * unreconciled. A placed one is left alone.
   */
  public function testInvoicePaidWithNoInvoiceInItPlacesDraftOrder(): void {
    $draft = $this->contract(state: 'draft');
    $object = ['object' => 'charge', 'id' => 'ch_1', 'metadata' => ['order_id' => (string) $draft->id()]];

    $this->assertTrue($this->body($this->post($this->event('invoice.paid', $object)))['handled']);
    $this->assertSame('completed', $this->reload($draft->id())->getState()->getId());
    $paid = $this->reload($draft->id())->getTotalPaid();
    $this->assertTrue($paid === NULL || $paid->isZero(), 'A payment was recorded from something that was not an invoice.');

    $placed = $this->contract();
    $object['metadata']['order_id'] = (string) $placed->id();
    $this->assertFalse($this->body($this->post($this->event('invoice.paid', $object)))['handled']);
  }

  /**
   * A failed collection is acknowledged and changes nothing yet.
   *
   * Stripe retries it, and the subscription ending is its own event.
   */
  public function testFailedPaymentIsAcknowledged(): void {
    $order = $this->contract();

    $failed = $this->invoice('in_failed', (int) $order->id());
    $failed['amount_paid'] = 0;

    $this->assertTrue($this->body($this->post($this->event('invoice.payment_failed', $failed)))['handled']);
    $this->assertSame('completed', $this->reload($order->id())->getState()->getId());
    $this->assertFalse($this->body($this->post($this->event('invoice.payment_failed', $this->invoice('in_other', 999999))))['handled']);
  }

  /**
   * The subscription ending is recorded once, with Stripe's own reason.
   */
  public function testTheSubscriptionEndingIsRecordedOnce(): void {
    $order = $this->contract();
    $ended = $this->subscription((int) $order->id(), endedAt: 1_760_000_000, reason: 'payment_failed');

    $this->assertTrue($this->body($this->post($this->event('customer.subscription.deleted', $ended)))['handled']);

    $order = $this->reload($order->id());
    $this->assertSame(1_760_000_000, (int) $order->getData(StripeSubscription::ENDED_KEY));
    $this->assertSame('payment_failed', (string) $order->getData(StripeSubscription::ENDED_REASON_KEY));

    // A redelivery carrying another reason does not replace the first.
    $again = $this->subscription((int) $order->id(), endedAt: 1_760_000_500, reason: 'cancellation_requested');
    $this->assertFalse($this->body($this->post($this->event('customer.subscription.deleted', $again)))['handled']);
    $this->assertSame('payment_failed', (string) $this->reload($order->id())->getData(StripeSubscription::ENDED_REASON_KEY));
  }

  /**
   * Without an end date, the cancellation date, and failing that, now.
   */
  public function testTheEndFallsBackToWhenItWasCancelled(): void {
    $cancelled = $this->contract();
    $event = $this->subscription((int) $cancelled->id(), endedAt: NULL, reason: '');
    $event['canceled_at'] = 1_750_000_000;
    $this->post($this->event('customer.subscription.deleted', $event));
    $this->assertSame(1_750_000_000, (int) $this->reload($cancelled->id())->getData(StripeSubscription::ENDED_KEY));
    $this->assertSame('', (string) $this->reload($cancelled->id())->getData(StripeSubscription::ENDED_REASON_KEY));

    $undated = $this->contract();
    $before = time();
    $this->post($this->event('customer.subscription.deleted', $this->subscription((int) $undated->id(), endedAt: NULL, reason: '')));
    $this->assertGreaterThanOrEqual($before, (int) $this->reload($undated->id())->getData(StripeSubscription::ENDED_KEY));
  }

  /**
   * Any other event is acknowledged and left alone.
   *
   * With a 200: Stripe retries anything else, and retrying an event this
   * deliberately ignores is a queue that never drains.
   */
  public function testAnyOtherEventIsAcknowledgedAndIgnored(): void {
    $order = $this->contract();

    foreach (['customer.created', 'charge.refunded', 'payment_intent.created'] as $type) {
      $response = $this->post($this->event($type, [
        'object' => 'customer',
        'id' => 'x',
        'metadata' => ['order_id' => (string) $order->id()],
      ]));

      $this->assertSame(200, $response->getStatusCode());
      $this->assertFalse($this->body($response)['handled'], $type . ' was acted on.');
    }
  }

  /**
   * Every verified event is dispatched, and a subscriber that acts says so.
   *
   * What this module does not handle (an identity check, a dispute) reaches
   * the site's own code through the same signed endpoint.
   */
  public function testOtherEventsAreHandedToSubscribers(): void {
    $this->container->get('event_dispatcher')->addListener(WebhookEvent::NAME, function (WebhookEvent $event): void {
      $this->seen[] = [(string) $event->event->type, $event->gateway->id(), $event->isHandled()];
      if (str_starts_with((string) $event->event->type, 'identity.')) {
        $event->setHandled();
      }
    });

    $session = [
      'object' => 'identity.verification_session',
      'id' => 'vs_1',
      'status' => 'verified',
    ];
    $response = $this->post($this->event('identity.verification_session.verified', $session));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue($this->body($response)['handled'], 'What the subscriber handled was reported as ignored.');

    // An event the subscriber leaves alone stays unhandled; one this module
    // handled reaches the subscriber already marked handled.
    $customer = ['object' => 'customer', 'id' => 'cus_1'];
    $this->assertFalse($this->body($this->post($this->event('customer.created', $customer)))['handled']);
    $order = $this->contract();
    $this->post($this->event('customer.subscription.deleted', $this->subscription((int) $order->id(), endedAt: 1_750_000_000, reason: '')));

    // An unsigned event reaches nobody: refused, and missing from the list.
    $unsigned = $this->post($this->event('customer.updated', ['object' => 'customer', 'id' => 'cus_2']), signWith: 'whsec_wrong');
    $this->assertSame(400, $unsigned->getStatusCode());

    $this->assertSame([
      ['identity.verification_session.verified', 'stripe_subscription', FALSE],
      ['customer.created', 'stripe_subscription', FALSE],
      ['customer.subscription.deleted', 'stripe_subscription', TRUE],
    ], $this->seen);
  }

  /**
   * Sends an event to the endpoint as Stripe would, and returns the answer.
   *
   * @param array<string, mixed> $event
   *   The event.
   * @param string|null $signWith
   *   The secret to sign with; the endpoint's own by default.
   * @param string|null $signature
   *   A `stripe-signature` header to send instead of a computed one.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface|null $gateway
   *   The gateway the event is addressed to; Stripe's by default.
   */
  private function post(array $event, ?string $signWith = NULL, ?string $signature = NULL, ?PaymentGatewayInterface $gateway = NULL): JsonResponse {
    $payload = json_encode($event + [
      'id' => 'evt_' . bin2hex(random_bytes(4)),
      'object' => 'event',
      'api_version' => StripeSubscription::API_VERSION,
    ], JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature ??= sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp . '.' . $payload, $signWith ?? self::SECRET));

    $request = Request::create('/stripe/subscription/stripe_subscription/webhook', 'POST', [], [], [], [], $payload);
    $request->headers->set('stripe-signature', $signature);

    $controller = WebhookController::create($this->container);

    return $controller->receive($request, $gateway ?? PaymentGateway::load('stripe_subscription'));
  }

  /**
   * The Stripe gateway, holding the given signing secret.
   */
  private function gateway(string $secret): PaymentGatewayInterface {
    $gateway = PaymentGateway::load('stripe_subscription') ?? PaymentGateway::create([
      'id' => 'stripe_subscription',
      'label' => 'Stripe',
      'plugin' => 'stripe_subscription',
    ]);
    $gateway->setPluginConfiguration([
      'webhook_secret' => $secret,
      'secret_key' => '',
      'publishable_key' => '',
      'mode' => 'test',
    ]);
    $gateway->save();

    return $gateway;
  }

  /**
   * An event of a type, carrying an object.
   *
   * @param string $type
   *   The event's type.
   * @param array<string, mixed> $object
   *   The object.
   *
   * @return array<string, mixed>
   *   The event.
   */
  private function event(string $type, array $object): array {
    return ['type' => $type, 'data' => ['object' => $object]];
  }

  /**
   * A checkout session that finished, for an order.
   *
   * @return array<string, mixed>
   *   The event.
   */
  private function checkout(int $order): array {
    return $this->event('checkout.session.completed', [
      'object' => 'checkout.session',
      'id' => 'cs_test',
      'client_reference_id' => (string) $order,
      'subscription' => 'sub_new',
      'customer' => 'cus_new',
    ]);
  }

  /**
   * A paid invoice for one period, naming its order as Stripe does.
   *
   * @return array<string, mixed>
   *   The invoice.
   */
  private function invoice(string $id, int $order): array {
    return [
      'object' => 'invoice',
      'id' => $id,
      'number' => 'INV-0042',
      'currency' => 'eur',
      'amount_paid' => 1250,
      'hosted_invoice_url' => 'https://stripe.test/i/' . $id,
      'period_start' => 1_700_000_000,
      'period_end' => 1_702_592_000,
      'status_transitions' => ['paid_at' => 1_700_000_500],
      'lines' => [
        'object' => 'list',
        'data' => [
          ['object' => 'line_item', 'description' => 'Plan S', 'amount' => 1250, 'quantity' => 1],
        ],
      ],
      'parent' => [
        'subscription_details' => [
          'subscription' => 'sub_existing',
          'metadata' => ['order_id' => (string) $order],
        ],
      ],
    ];
  }

  /**
   * A subscription that ended.
   *
   * @return array<string, mixed>
   *   The subscription.
   */
  private function subscription(int $order, ?int $endedAt, string $reason): array {
    return [
      'object' => 'subscription',
      'id' => 'sub_existing',
      'status' => 'canceled',
      'ended_at' => $endedAt,
      'canceled_at' => NULL,
      'cancellation_details' => ['reason' => $reason === '' ? NULL : $reason],
      'metadata' => ['order_id' => (string) $order],
    ];
  }

  /**
   * The order a subscription was bought on, as Checkout leaves it.
   */
  private function contract(string $state = 'completed', ?string $subscription = 'sub_existing'): Order {
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
      'payment_gateway' => 'stripe_subscription',
      'order_items' => [$item],
    ]);
    if ($subscription !== NULL) {
      $order->setData(StripeSubscription::SUBSCRIPTION_KEY, $subscription);
    }
    $order->save();

    return $order;
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

  /**
   * A response's JSON.
   *
   * @return array<string, mixed>
   *   The body.
   */
  private function body(JsonResponse $response): array {
    return json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

}
