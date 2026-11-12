<?php

declare(strict_types=1);

namespace Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_stripe_recurring\PluginForm\StripeSubscriptionRedirect;
use Drupal\commerce_price\Price;
use Drupal\commerce_stripe_recurring\Conventions;
use Drupal\commerce_stripe_recurring\RecurringGatewayInterface;
use Drupal\commerce_stripe_recurring\OrderLock;
use Drupal\Core\State\StateInterface;
use Drupal\commerce_stripe_recurring\StripeCredentialsInterface;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Bills an order by handing it to a Stripe subscription.
 *
 * The customer is sent to Stripe Checkout in `mode: subscription`. Stripe
 * collects the payment method, takes a mandate where the instrument needs
 * one, handles SCA and creates the subscription; this records which
 * subscription pays for the order and listens for what Stripe says
 * afterwards.
 *
 * Nothing sensitive arrives here, which is the whole of the PCI argument —
 * and the reason this is a redirect rather than Payment Element.
 */
#[CommercePaymentGateway(
  id: 'stripe_subscription',
  label: new TranslatableMarkup('Stripe subscription'),
  display_label: new TranslatableMarkup('Card or direct debit'),
  forms: [
    // A concrete subclass. PaymentOffsiteForm is abstract and exists to be
    // inherited from — naming it here gets as far as the payment step and
    // then cannot be instantiated.
    'offsite-payment' => StripeSubscriptionRedirect::class,
  ],
  modes: [
    'test' => new TranslatableMarkup('Test'),
    'live' => new TranslatableMarkup('Live'),
  ],
  // Its own type rather than `credit_card`, which is the default and which
  // would have this module claiming a card for a customer who may well be
  // paying by direct debit. Nothing is stored on it either way: Stripe holds
  // the payment method and the mandate, and this side keeps the
  // subscription's id.
  payment_method_types: ['stripe_subscription'],
  requires_billing_information: FALSE,
)]
final class StripeSubscription extends OffsitePaymentGatewayBase implements RecurringGatewayInterface {

  /**
   * The Stripe API version this module is written against.
   *
   * Pinned deliberately. Stripe moves fields between releases — an invoice's
   * subscription used to be `invoice.subscription` and is now under
   * `invoice.parent.subscription_details` — and an integration that accepts
   * whatever the account happens to be defaulted to is one that breaks on a
   * day nobody deployed anything.
   */
  public const string API_VERSION = '2025-09-30.clover';

  /**
   * Where the subscription's id is kept on the order.
   */
  public const string SUBSCRIPTION_KEY = 'stripe_subscription_id';

  /**
   * Where the Checkout session's id is kept while the customer is away.
   */
  public const string SESSION_KEY = 'stripe_checkout_session_id';

  /**
   * Where the end of a subscription is recorded, and why it ended.
   *
   * Two facts rather than one, because they mean different things to
   * whatever reads them. A subscription the customer cancelled and one
   * Stripe gave up on after three weeks of failed retries both arrive as
   * the same event, and only the reason separates "they left" from "they
   * stopped paying".
   */
  public const string ENDED_KEY = 'stripe_subscription_ended';
  public const string ENDED_REASON_KEY = 'stripe_subscription_ended_reason';

  /**
   * Where the gateway's id for this customer is read from and written back.
   *
   * On the order because that is all this module can reach. Whose customer
   * it really is — a user, a team, an account — is the site's question, and
   * the site mirrors this key onto whatever it keeps. Left to the
   * order alone, a customer buying a second thing would become a second
   * customer at Stripe, with their card saved against only one of them.
   */
  public const string CUSTOMER_KEY = 'stripe_customer_id';

  /**
   * Where the number of months between charges is read from on the order.
   *
   * Set by whatever knows what was bought. Absent, the gateway's own
   * default applies.
   */
  public const string INTERVAL_KEY = 'stripe_subscription_interval_months';

  /**
   * On the order: how many days of trial it carries, when it carries one.
   *
   * Written by whatever decides who gets a trial; this module only honours
   * it. An order that says nothing falls back to the gateway's own setting.
   */
  public const string TRIAL_KEY = 'stripe_trial_days';

  /**
   * On the order: the subscriptions of checkouts opened for something else.
   *
   * A checkout is opened for the order's lines and trial as they stood then.
   * One that completes after the order has changed bills what it showed, not
   * what the order holds, so it never places the order (OrderEvents). The
   * money may have moved all the same: the subscriptions are listed here, by
   * id, for the site to cancel and refund.
   */
  public const string MISMATCH_KEY = 'stripe_checkout_mismatch';

  /**
   * The Stripe client, built on first use.
   */
  protected ?StripeClient $stripe = NULL;

  /**
   * Where the keys come from: the gateway's own form, or a site's settings.
   */
  protected StripeCredentialsInterface $credentials;

  /**
   * The lock every write to a subscription order takes.
   */
  protected OrderLock $orderLock;

  /**
   * Keeps the portal configuration's id per mode.
   */
  protected StateInterface $state;

  /**
   * The names the site chose.
   */
  protected Conventions $conventions;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->credentials = $container->get('commerce_stripe_recurring.credentials');
    $instance->orderLock = $container->get('commerce_stripe_recurring.order_lock');
    $instance->state = $container->get('state');
    $instance->conventions = $container->get('commerce_stripe_recurring.conventions');

    return $instance;
  }

  /**
   * Stripe, talked to through a client rather than through globals.
   *
   * `Stripe::setApiKey()` sets the key for the whole process. That is fine
   * with one account and wrong the moment there are two: whichever call ran
   * last decides who the next one bills. A client carries its own key, so a
   * gateway can only ever spend its own account's credentials.
   *
   * Refuses (PaymentGatewayException) when the credentials do: a key of the
   * wrong mode, or Stripe switched off, never reaches the network.
   */
  private function stripe(): StripeClient {
    if ($this->stripe !== NULL) {
      return $this->stripe;
    }

    $config = [
      'api_key' => $this->credentials->secretKey($this->configuration),
      // Named rather than left to the library's default, so upgrading the
      // library is not also an unannounced upgrade of the wire format. The
      // shapes this module reads — an invoice's parent, a line item's
      // amount — belong to a version, and that version is stated here.
      'stripe_version' => self::API_VERSION,
    ];

    // Stripe's own double in tests, never set in production.
    if (($base = $this->credentials->apiBase()) !== NULL) {
      $config['api_base'] = $base;
    }

    return $this->stripe = new StripeClient($config);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    return [
      'secret_key' => '',
      'publishable_key' => '',
      'webhook_secret' => '',
      // Left to Stripe by default. Checkout offers whatever the account has
      // enabled, minus anything the order's currency or the customer's
      // country rules out — it drops SEPA from a dollar subscription on its
      // own, for instance. Naming a list here instead means maintaining a
      // second copy of the dashboard's settings that can only fall behind.
      //
      // The restriction exists for the one case Stripe cannot know about:
      // an instrument that is *allowed* but that this site must not
      // offer to a particular kind of customer.
      'restrict_methods' => FALSE,
      // Stripe's instrument names, not Commerce's payment method types.
      // Emphatically not called `payment_method_types`: that key belongs to
      // PaymentGatewayBase, which renders it, reads it back as an array of
      // checkboxes and calls array_keys() on it. Borrowing the name put a
      // comma-separated string where the parent expected an array and threw
      // a TypeError on every save of the gateway.
      'instruments' => [],
      // How often to charge, when the order does not say. Monthly is the
      // common case; a longer period is a property of what was bought, so
      // it travels on the order rather than being configured here.
      'interval_months' => 1,
      'trial_days' => 0,
      // Which customer portal billingPortalUrl() opens: `payment_methods`, a
      // configuration this module keeps that offers payment methods and
      // invoices only; or `dashboard`, the account's default configuration
      // as set in the Stripe dashboard, which may also allow cancelling or
      // switching plans behind the site's back.
      'portal' => 'payment_methods',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The built form.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret key'),
      '#default_value' => $this->configuration['secret_key'],
      '#description' => $this->t('Ignored where the site supplies the keys from its settings.'),
    ];

    $form['publishable_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Publishable key'),
      '#default_value' => $this->configuration['publishable_key'],
    ];

    $form['webhook_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook signing secret'),
      '#default_value' => $this->configuration['webhook_secret'],
      '#description' => $this->t('From the endpoint in the Stripe dashboard. Without it nothing Stripe sends can be trusted and no order is marked paid. Ignored where the site supplies it from its settings.'),
    ];

    $form['restrict_methods'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Offer only the methods named below'),
      '#default_value' => $this->configuration['restrict_methods'],
      '#description' => $this->t('Off, Stripe offers whatever the account has enabled and the order is eligible for — it already leaves out anything the currency or the customer’s country rules out. Switch this on only to withhold something Stripe would otherwise allow.'),
    ];

    $form['instruments'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Methods'),
      '#default_value' => implode(', ', $this->configuration['instruments']),
      '#description' => $this->t('Stripe’s own identifiers, comma separated: <code>card</code>, <code>sepa_debit</code>, <code>bacs_debit</code>, <code>link</code>, and so on. A free field rather than a list of checkboxes because Stripe adds methods faster than this form could be kept in step with, and a stale list would quietly withhold one that was enabled.'),
      '#states' => [
        'visible' => [':input[name$="[restrict_methods]"]' => ['checked' => TRUE]],
      ],
    ];

    $form['interval_months'] = [
      '#type' => 'number',
      '#title' => $this->t('Months between charges'),
      '#default_value' => $this->configuration['interval_months'],
      '#min' => 1,
      '#max' => 12,
      '#description' => $this->t('Used when an order does not say. An order that was sold as a quarter or a year carries its own figure and that wins.'),
    ];

    $form['trial_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Trial days'),
      '#default_value' => $this->configuration['trial_days'],
      '#min' => 0,
      '#max' => 365,
      '#description' => $this->t('Nothing is collected until the trial ends. A SEPA mandate is still taken at the start, which is what makes the first collection afterwards work.'),
    ];

    $form['portal'] = [
      '#type' => 'radios',
      '#title' => $this->t('Customer portal'),
      '#default_value' => $this->configuration['portal'],
      '#options' => [
        'payment_methods' => $this->t('Payment methods and invoices only'),
        'dashboard' => $this->t('The default configuration in the Stripe dashboard'),
      ],
      '#description' => $this->t('The first is a portal configuration this module creates and keeps: customers update their payment method and read their invoices, and nothing else. The second is whatever the Stripe dashboard allows, which may include cancelling or changing the subscription outside this site.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);

    $this->configuration['secret_key'] = trim((string) $values['secret_key']);
    $this->configuration['publishable_key'] = trim((string) $values['publishable_key']);
    $this->configuration['webhook_secret'] = trim((string) $values['webhook_secret']);
    $this->configuration['restrict_methods'] = (bool) $values['restrict_methods'];
    $this->configuration['instruments'] = array_values(array_filter(array_map(
      trim(...),
      explode(',', (string) $values['instruments']),
    )));
    $this->configuration['interval_months'] = max(1, min(12, (int) $values['interval_months']));
    $this->configuration['trial_days'] = (int) $values['trial_days'];
    $this->configuration['portal'] = $values['portal'] === 'dashboard' ? 'dashboard' : 'payment_methods';
  }

  /**
   * {@inheritdoc}
   *
   * The instruments are a *choice*, not a set of defaults to be added to.
   * PaymentGatewayBase merges configuration into the defaults with
   * NestedArray::mergeDeep, which unions sequences — so a gateway saved with
   * card deliberately unchecked came back with card enabled again the next
   * time its configuration was set, which is what a config import does. The
   * list is replaced rather than merged.
   *
   * @param array<string, mixed> $configuration
   *   The configuration to set.
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);

    if (array_key_exists('instruments', $configuration)) {
      $this->configuration['instruments'] = array_values(array_unique(
        (array) $configuration['instruments'],
      ));
    }
  }

  /**
   * The Checkout session a customer is sent to.
   *
   * Built here rather than in the offsite form so that the form stays a
   * redirect and nothing about Stripe leaks into a render array.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order being subscribed.
   * @param string $returnUrl
   *   Where Stripe sends somebody who finished.
   * @param string $cancelUrl
   *   Where Stripe sends somebody who did not.
   *
   * @return \Stripe\Checkout\Session
   *   The session, whose URL is the redirect target.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When Stripe refuses to open one.
   */
  public function createCheckoutSession(OrderInterface $order, string $returnUrl, string $cancelUrl): CheckoutSession {
    $lineItems = $this->lineItems($order);
    $trial = $this->trialOf($order);

    // What this checkout is opened for travels with it, on the session and
    // on the subscription it makes, so whatever it goes on to pay for can be
    // held against the order as the order stands then (contentsOf()).
    $metadata = $this->metadata($order) + [
      $this->conventions->contentsMetadataKey => self::digestOf($lineItems, $trial),
    ];
    $subscriptionData = ['metadata' => $metadata];

    if ($trial > 0) {
      $subscriptionData['trial_period_days'] = $trial;
      // A trial with nothing to charge at its end is not a trial, it is a
      // gift. The card is taken now — Stripe's default for subscriptions,
      // said here so that nobody turns it off without reading this — and a
      // subscription that somehow reaches the end without one stops rather
      // than running on unpaid.
      $subscriptionData['trial_settings'] = ['end_behavior' => ['missing_payment_method' => 'cancel']];
    }

    // One checkout at a time: the one the order was sent to before, if it
    // is still open, is closed before another is opened.
    $this->closeEarlierSession($order);

    try {
      $parameters = [
        'mode' => 'subscription',
        'line_items' => $lineItems,
        'success_url' => $returnUrl,
        'cancel_url' => $cancelUrl,
        'client_reference_id' => (string) $order->id(),
        'subscription_data' => $subscriptionData,
        'metadata' => $metadata,
      ];

      if ($trial > 0) {
        $parameters['payment_method_collection'] = 'always';
      }

      // An existing customer, or an address to make one from — never both.
      // Stripe refuses a session carrying `customer` and `customer_email`
      // together, and the reason to prefer the id is the whole point of
      // keeping it: a returning customer sees the card they already saved
      // instead of typing it again into a second account.
      $customer = (string) $order->getData(self::CUSTOMER_KEY);

      if ($customer !== '') {
        $parameters['customer'] = $customer;
      }
      elseif (($email = $order->getEmail()) !== NULL && $email !== '') {
        $parameters['customer_email'] = $email;
      }

      // Omitted unless something is being deliberately withheld. Sending the
      // key at all turns off Stripe's own eligibility filtering, so a list
      // that has not kept up with the dashboard would narrow the checkout
      // rather than widen it.
      if ($this->configuration['restrict_methods']) {
        $parameters['payment_method_types'] = $this->instrumentsFor($lineItems);
      }

      $session = $this->stripe()->checkout->sessions->create($parameters);
    }
    catch (ApiErrorException $e) {
      // The message is Stripe's and is safe to surface: it says what was
      // wrong with the request, not anything about the account.
      throw new PaymentGatewayException(sprintf('Stripe refused to open a subscription checkout: %s', $e->getMessage()), 0, $e);
    }

    $this->orderLock->update($order, static function (OrderInterface $locked) use ($session): void {
      $locked->setData(self::SESSION_KEY, $session->id);
    });

    return $session;
  }

  /**
   * The instruments Stripe can actually offer for this order.
   *
   * SEPA Direct Debit is denominated in euro and nothing else. Asked to
   * offer it on a dollar subscription, Stripe does not refuse — it drops it
   * from the list and opens the checkout with whatever is left, so the page
   * quietly shows card only and nobody learns why.
   *
   * That silence is the problem worth guarding against here. A site
   * whose card-less customers depend on direct debit would find them sent to
   * a page offering exactly the instrument they may not use, with no error
   * anywhere to explain it. So the filtering is done here, deliberately,
   * and an order that ends up with nothing left is refused rather than
   * opened.
   *
   * Read off the line items being sent rather than the order's total. The
   * total can be unset on an order that has not been refreshed, and an
   * earlier version defaulted to euro when it could not tell — which
   * offered a euro-only instrument for an order whose currency nobody knew.
   * The items are what Stripe is actually being given, so they are what
   * decides.
   *
   * @param array<int, array<string, mixed>> $lineItems
   *   The line items about to be sent.
   *
   * @return array<int, string>
   *   Stripe payment method types.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the currency rules out everything configured.
   */
  private function instrumentsFor(array $lineItems): array {
    $currency = strtolower((string) ($lineItems[0]['price_data']['currency'] ?? ''));
    $offered = [];

    foreach ($this->configuration['instruments'] as $instrument) {
      if ($instrument === 'sepa_debit' && $currency !== 'eur') {
        continue;
      }

      $offered[] = $instrument;
    }

    if ($offered === []) {
      throw new PaymentGatewayException(sprintf(
        'Nothing this gateway offers can bill a subscription in %s. SEPA Direct Debit is euro only.',
        $currency === '' ? 'that currency' : strtoupper($currency),
      ));
    }

    return $offered;
  }

  /**
   * The order's items, as recurring prices Stripe can bill.
   *
   * Priced inline rather than against a Stripe Price object. The catalogue
   * is the site's, in Commerce, and mirroring it into Stripe would mean two
   * catalogues that can disagree about what somebody is paying.
   *
   * A flat charge for the period, not metered usage. Somebody who bought
   * three of something pays for three whether or not they used all three,
   * which is what a subscription is. Usage billed in arrears goes through
   * addToNextInvoice().
   *
   * What a line's price is for is the site's to say (Conventions). By
   * default it is the amount of one charge, however many months lie between
   * two of them. Where the site prices its orders for one month, the lines
   * are sent for the whole period instead (forThePeriod()).
   *
   * Every caller reads the lines from here: the checkout, the invoiced
   * subscription, a revision, the comparison with what Stripe bills and the
   * record of what a checkout was opened for. So they cannot disagree about
   * an amount.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param bool $recurring
   *   FALSE for a one-off payment.
   *
   * @return array<int, array<string, mixed>>
   *   Stripe line items.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the order has nothing Stripe can bill.
   */
  private function lineItems(OrderInterface $order, bool $recurring = TRUE): array {
    $items = [];
    $recurrence = $recurring ? $this->recurrenceOf($order) : NULL;

    foreach ($order->getItems() as $item) {
      // An item that has been closed, or is waiting for Stripe to agree to
      // a change, is not what the subscription bills (Conventions).
      if (!$this->conventions->billsItem($item)) {
        continue;
      }
      // The *adjusted* price, which is the one with tax in it.
      //
      // Prices on this store are net and VAT arrives as an adjustment, so
      // `getUnitPrice()` is the figure before tax — and a gateway billing it
      // charges a customer their net total and collects no VAT at all. On an
      // order of 37.87 that is 9.47 nobody asked Stripe for, every period,
      // silently, with the Commerce invoice saying 47.34 the whole time.
      //
      // Sent as one tax-inclusive amount rather than as Stripe tax rates on
      // a net line. Stripe would then hold a second copy of the rates, and
      // two tax engines that can disagree about what somebody owes is worse
      // than the problem being solved — the same reason the catalogue is not
      // pushed into Stripe either. Commerce computes the tax; Stripe collects
      // the total; the invoice the customer reads is rendered here.
      $price = $item->getAdjustedUnitPrice();

      if ($price === NULL) {
        continue;
      }

      $amount = $this->minorUnitsConverter->toMinorUnits($price);

      // A line at nothing is real — something included in another line is
      // on the order at zero so the invoice names it — but Stripe refuses a
      // recurring price of 0. It stays on the Commerce order,
      // which is what the customer's invoice is rendered from.
      if ($amount <= 0) {
        continue;
      }

      $line = [
        'quantity' => (int) $item->getQuantity(),
        'price_data' => [
          'currency' => strtolower($price->getCurrencyCode()),
          'unit_amount' => $amount,
          'product_data' => ['name' => (string) $item->getTitle()],
        ],
      ];

      // A one-off payment: no recurrence.
      if ($recurrence !== NULL) {
        $line['price_data']['recurring'] = $recurrence;
      }

      $items[] = $line;
    }

    if ($items === []) {
      throw new PaymentGatewayException('There is nothing on this order that Stripe can bill.');
    }

    if ($this->conventions->monthlyPrices) {
      return $this->forThePeriod($items, $order, (int) ($recurrence['interval_count'] ?? 1));
    }

    return $items;
  }

  /**
   * The lines of an order priced for one month, as amounts for the period.
   *
   * Only where the site says its prices are for one month
   * (Conventions::$monthlyPrices). Such an order's total is what one month
   * costs, and a subscription charged every three months owes three of
   * them: sent as it stands, Stripe would collect one month's amount once a
   * quarter. So each line is multiplied by the months between charges.
   *
   * The order's own adjustments come along. A discount for paying ahead is
   * on the order, not on a line, and Stripe is given lines only. Each line
   * takes its share, in proportion to what it costs, so that what Stripe
   * collects for a period is the order's total times the months in it. A
   * one-off payment has no months, and takes the adjustments alone.
   *
   * To the cent wherever a line of one unit exists to take what rounding
   * leaves. A line of several units can only move by its quantity, so an
   * order of nothing but such lines may come out a few cents either side.
   *
   * An adjustment that should be collected once, such as a credit, does not
   * belong on a subscription order here: it would come off every period.
   *
   * @param array<int, array<string, mixed>> $lines
   *   The lines, at one month's amount.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $months
   *   The months between charges; 1 for a one-off payment.
   *
   * @return array<int, array<string, mixed>>
   *   The lines, at the period's amount.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the adjustments leave nothing to bill.
   */
  private function forThePeriod(array $lines, OrderInterface $order, int $months): array {
    $months = max(1, $months);
    $currency = (string) $lines[0]['price_data']['currency'];

    // The order's own adjustments, for the period. Those of the lines are in
    // the adjusted unit price already, and an included one is in the price
    // it was included in.
    $adjustment = 0;

    foreach ($order->getAdjustments() as $candidate) {
      $amount = $candidate->getAmount();

      if ($candidate->isIncluded() || strtolower($amount->getCurrencyCode()) !== $currency) {
        continue;
      }

      $adjustment += $this->minorUnitsConverter->toMinorUnits($amount);
    }

    $adjustment *= $months;

    $units = [];
    $whole = 0;

    foreach ($lines as $index => $line) {
      $units[$index] = (int) $line['price_data']['unit_amount'] * $months;
      $whole += $units[$index] * (int) $line['quantity'];
    }

    if ($adjustment !== 0) {
      $owed = $whole + $adjustment;

      // A discount as large as the order: Stripe refuses a price of nothing,
      // and a subscription that collects nothing is not one.
      if ($owed <= 0 || $whole <= 0) {
        throw new PaymentGatewayException('There is nothing on this order that Stripe can bill.');
      }

      foreach ($units as $index => $unit) {
        // Never to nothing: a line that is on the order at a price is billed
        // at one.
        $units[$index] = max(1, $unit + self::shareOf($adjustment, $unit, $whole));
        $owed -= $units[$index] * (int) $lines[$index]['quantity'];
      }

      // What rounding left, a cent or two, on the lines that can take it: a
      // line moves by its quantity, so the largest quantities go first and a
      // line of one unit takes the rest.
      $quantities = array_map(static fn (array $line): int => (int) $line['quantity'], $lines);
      $byQuantity = array_keys($units);
      usort($byQuantity, static fn (int $a, int $b): int => $quantities[$b] <=> $quantities[$a] ?: $a <=> $b);

      foreach ($byQuantity as $index) {
        $quantity = $quantities[$index];

        if ($owed === 0) {
          break;
        }

        if ($quantity < 1) {
          continue;
        }

        $steps = intdiv(abs($owed), $quantity);
        $steps = $owed < 0 ? min($steps, $units[$index] - 1) : $steps;
        $change = $owed < 0 ? -$steps : $steps;

        $units[$index] += $change;
        $owed -= $change * $quantity;
      }
    }

    foreach ($units as $index => $unit) {
      $lines[$index]['price_data']['unit_amount'] = $unit;
    }

    return $lines;
  }

  /**
   * A part's share of an amount, in proportion to the whole, rounded.
   *
   * In whole minor units throughout, halves away from zero as Commerce
   * rounds, so the same order always comes to the same amounts.
   */
  private static function shareOf(int $amount, int $part, int $whole): int {
    $product = $amount * $part;
    $half = intdiv($whole, 2);

    return $product < 0 ? -intdiv($half - $product, $whole) : intdiv($product + $half, $whole);
  }

  /**
   * The days of trial an order's subscription starts with.
   *
   * The order's own, where it has one: a trial is decided per customer, and
   * the gateway's setting is only what applies to everybody else.
   */
  private function trialOf(OrderInterface $order): int {
    $own = $order->getData(self::TRIAL_KEY);

    return max(0, (int) ($own ?? $this->configuration['trial_days'] ?? 0));
  }

  /**
   * What a checkout of this order would be opened for, as one value.
   *
   * The lines Stripe would be sent, each with its currency, amount,
   * quantity, name and recurrence, and the days of trial. A checkout carries
   * the value it was opened with (Conventions::$contentsMetadataKey), on the
   * session and on the subscription it makes, and Stripe copies the
   * subscription's onto every invoice. Computed again from the order when
   * the checkout comes back, it says whether the order still holds what the
   * customer was shown and paid for.
   *
   * Nothing is kept on the order, so nothing has to be cleared when the
   * order changes. Read from the order as it is stored: a caller holding an
   * older copy reloads it first.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param bool $recurring
   *   FALSE for a one-off payment (createPaymentCheckoutSession()).
   *
   * @return string
   *   The value. An order with nothing to bill has one too, which no
   *   checkout was ever opened with.
   */
  public function contentsOf(OrderInterface $order, bool $recurring = TRUE): string {
    try {
      $lines = $this->lineItems($order, $recurring);
    }
    catch (PaymentGatewayException) {
      $lines = [];
    }

    return self::digestOf($lines, $recurring ? $this->trialOf($order) : 0);
  }

  /**
   * Whether a Stripe object was opened for the order as it stands now.
   *
   * For a Checkout session, a subscription, or an invoice of one. FALSE also
   * when the object does not say what it was opened for.
   */
  public function openedFor(OrderInterface $order, object $object): bool {
    $claimed = $this->conventions->contentsClaimedBy($object);
    $recurring = ($object->mode ?? 'subscription') !== 'payment';

    return $claimed !== '' && hash_equals($this->contentsOf($order, $recurring), $claimed);
  }

  /**
   * One value for a list of lines and a trial.
   *
   * A list rather than a set: two lines alike count twice. Sorted, so the
   * order of the lines is not a difference.
   *
   * @param array<int, array<string, mixed>> $lines
   *   Lines as lineItems() builds them.
   * @param int $trial
   *   Days of trial.
   */
  private static function digestOf(array $lines, int $trial): string {
    $entries = [];

    foreach ($lines as $line) {
      $price = $line['price_data'] ?? [];
      $recurring = $price['recurring'] ?? [];

      $entries[] = json_encode([
        (string) ($price['currency'] ?? ''),
        (int) ($price['unit_amount'] ?? 0),
        (int) ($line['quantity'] ?? 0),
        (string) ($price['product_data']['name'] ?? ''),
        (string) ($recurring['interval'] ?? ''),
        (int) ($recurring['interval_count'] ?? 0),
      ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    sort($entries, SORT_STRING);

    return hash('sha256', implode("\n", $entries) . "\n" . 'trial:' . max(0, $trial));
  }

  /**
   * Closes the checkout the order was sent to before, where it can be.
   *
   * A courtesy to the customer, who should have one page that can take
   * their money, and not what keeps an older checkout from placing the
   * order: that is the comparison with what it was opened for. So a Stripe
   * that will not answer does not stop the next checkout from opening.
   */
  private function closeEarlierSession(OrderInterface $order): void {
    try {
      $this->expireCheckoutSession($order);
    }
    catch (PaymentGatewayException) {
      // Still open, perhaps. Whatever it goes on to pay for is compared.
    }
  }

  /**
   * How often Stripe should charge this order.
   *
   * Months, and how many of them. A quarter or a year is bought and paid
   * ahead — that is the discount somebody chose — and billing it monthly
   * would charge a twelfth of what was agreed, twelve times, at the wrong
   * price. So the interval is asked for rather than assumed.
   *
   * Read off the order rather than from a site's own vocabulary: this
   * module is meant to be liftable, and a gateway that imported another
   * module's billing-period enum would not be. Whatever knows the period
   * writes the number of months onto the order under INTERVAL_KEY; an order
   * that says nothing is billed monthly, which is the common case and a
   * safe default.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array{interval: string, interval_count: int}
   *   Stripe's recurrence.
   */
  private function recurrenceOf(OrderInterface $order): array {
    $months = (int) ($order->getData(self::INTERVAL_KEY) ?: $this->configuration['interval_months']);

    // Stripe counts subscriptions in months, so a quarter is three of them
    // rather than an interval of its own. Held to a year, which is as far
    // ahead as anything here is sold.
    return ['interval' => 'month', 'interval_count' => max(1, min(12, $months))];
  }

  /**
   * What Stripe should carry back about this order.
   *
   * The order id, the owner where the site names one, and the site where
   * settings.php names it (Conventions). All are a cross-check when an event
   * comes back, never what decides who an order belongs to: that is the
   * order's own field. No address, no name: this is metadata on a third
   * party's record.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array<string, string>
   *   Metadata.
   */
  private function metadata(OrderInterface $order): array {
    $metadata = ['order_id' => (string) $order->id()];

    $owner = $this->conventions->ownerOf($order);

    if ($owner !== '' && $this->conventions->ownerMetadataKey !== '') {
      $metadata[$this->conventions->ownerMetadataKey] = $owner;
    }

    return $metadata + $this->conventions->siteMetadata();
  }

  /**
   * {@inheritdoc}
   *
   * `cancel_at` rather than `cancel_at_period_end`, because the date is not
   * always this period's end: somebody who gives notice inside the notice
   * window is cancelling from the end of the period after it, which is what
   * they agreed to and what the site told them. A flag can only express
   * one of those two answers.
   *
   * Stripe goes on collecting until the date and then stops on its own, so
   * nothing has to run here later to finish the job.
   */
  public function cancelSubscriptionAt(OrderInterface $order, int $timestamp): bool {
    $subscription = $this->subscriptionOf($order);

    if ($subscription === '') {
      // Never handed to this gateway — an order that was checked out some
      // other way, or one whose checkout was abandoned. Not an error: there
      // is simply nothing at the far end to stop.
      return FALSE;
    }

    try {
      $this->stripe()->subscriptions->update($subscription, ['cancel_at' => $timestamp]);
    }
    catch (ApiErrorException $e) {
      // Never swallowed. A cancellation recorded on this side and refused on
      // Stripe's is a subscription that goes on taking money from somebody
      // who has been told it stopped, and the only thing worse than failing
      // here is appearing to succeed.
      throw new PaymentGatewayException(sprintf(
        'Stripe would not cancel subscription %s: %s',
        $subscription,
        $e->getMessage(),
      ), 0, $e);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function revisionApplied(OrderInterface $order): bool {
    $subscription = $this->subscriptionOf($order);

    if ($subscription === '') {
      throw new PaymentGatewayException('The order has no Stripe subscription to compare.');
    }

    try {
      $remote = $this->stripe()->subscriptions->retrieve($subscription, ['expand' => ['items.data.price.product']]);
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe would not describe subscription %s: %s', $subscription, $e->getMessage()), 0, $e);
    }

    $wanted = [];
    foreach ($this->lineItems($order) as $line) {
      $wanted[self::signatureOf($line)] = $line;
    }

    foreach ($remote->items->data ?? [] as $item) {
      $signature = $this->matchFor($item, $wanted);
      if ($signature === '' || !isset($wanted[$signature]) || (int) $item->quantity !== (int) $wanted[$signature]['quantity']) {
        return FALSE;
      }
      unset($wanted[$signature]);
    }

    return $wanted === [];
  }

  /**
   * {@inheritdoc}
   */
  public function expireCheckoutSession(OrderInterface $order): void {
    $id = (string) $order->getData(self::SESSION_KEY);

    if ($id === '') {
      return;
    }

    try {
      $this->stripe()->checkout->sessions->expire($id);
    }
    catch (ApiErrorException $e) {
      // Already expired or completed, or never there: nothing left to stop.
      if ($e->getStripeCode() === 'resource_missing' || str_contains(strtolower($e->getMessage()), 'expire')) {
        return;
      }
      throw new PaymentGatewayException(sprintf('Stripe would not expire checkout session %s: %s', $id, $e->getMessage()), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resumeSubscription(OrderInterface $order): bool {
    $subscription = $this->subscriptionOf($order);

    if ($subscription === '') {
      return FALSE;
    }

    try {
      // An empty `cancel_at` removes the cancellation date.
      $this->stripe()->subscriptions->update($subscription, ['cancel_at' => '']);
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe would not resume subscription %s: %s', $subscription, $e->getMessage()), 0, $e);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function findOpenCheckoutSession(OrderInterface $order): ?CheckoutSession {
    $id = (string) $order->getData(self::SESSION_KEY);

    if ($id === '') {
      return NULL;
    }

    try {
      $session = $this->stripe()->checkout->sessions->retrieve($id);
    }
    catch (ApiErrorException $e) {
      if ($e->getStripeCode() === 'resource_missing') {
        return NULL;
      }
      throw new PaymentGatewayException(sprintf('Stripe would not describe checkout session %s: %s', $id, $e->getMessage()), 0, $e);
    }

    return ($session->status ?? NULL) === 'open' && ($session->url ?? '') !== '' ? $session : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function refundFirstInvoice(OrderInterface $order): ?string {
    $subscription = $this->subscriptionOf($order);

    if ($subscription === '') {
      return NULL;
    }

    try {
      $remote = $this->stripe()->subscriptions->retrieve($subscription, ['expand' => ['latest_invoice']]);
    }
    catch (ApiErrorException $e) {
      if ($e->getStripeCode() === 'resource_missing') {
        return '';
      }
      throw new PaymentGatewayException(sprintf('Stripe would not describe subscription %s: %s', $subscription, $e->getMessage()), 0, $e);
    }

    $invoice = $remote->latest_invoice ?? NULL;
    if (!is_object($invoice)) {
      return NULL;
    }
    $status = (string) ($invoice->status ?? '');
    if (in_array($status, ['void', 'uncollectible'], TRUE) || ($status === 'paid' && (int) ($invoice->amount_paid ?? 0) <= 0)) {
      return '';
    }
    $intent = $invoice->payment_intent ?? NULL;
    $intent = is_object($intent) ? ($intent->id ?? NULL) : $intent;

    if ((int) ($invoice->amount_paid ?? 0) <= 0 || !is_string($intent) || $intent === '') {
      return NULL;
    }

    return $this->refundPayment($intent, NULL, 'duplicate');
  }

  /**
   * {@inheritdoc}
   */
  public function cancelSubscriptionNow(OrderInterface $order): bool {
    $subscription = $this->subscriptionOf($order);

    if ($subscription === '') {
      return FALSE;
    }

    try {
      $this->stripe()->subscriptions->cancel($subscription, ['invoice_now' => FALSE, 'prorate' => FALSE]);
    }
    catch (ApiErrorException $e) {
      // Already gone is what was wanted.
      if ($e->getStripeCode() === 'resource_missing') {
        return TRUE;
      }

      throw new PaymentGatewayException(sprintf('Stripe would not cancel subscription %s: %s', $subscription, $e->getMessage()), 0, $e);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * Read from the subscription's default payment method, which Checkout sets
   * when it completes. Stripe gives a card and a SEPA or BACS account the
   * same kind of fingerprint — equal for the same instrument across
   * customers — under the name of the method's type.
   */
  public function paymentFingerprint(OrderInterface $order): ?string {
    $subscription = $this->subscriptionOf($order);

    if ($subscription === '') {
      return NULL;
    }

    try {
      $remote = $this->stripe()->subscriptions->retrieve($subscription, ['expand' => ['default_payment_method']]);
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe would not say how subscription %s is paid: %s', $subscription, $e->getMessage()), 0, $e);
    }

    $method = $remote->default_payment_method ?? NULL;
    $type = is_object($method) ? (string) ($method->type ?? '') : '';
    $fingerprint = $type === '' ? '' : (string) ($method->{$type}->fingerprint ?? '');

    return $fingerprint === '' ? NULL : $type . ':' . $fingerprint;
  }

  /**
   * The order's subscription at Stripe, recovered where the order lost it.
   *
   * An order that went through this gateway's checkout and holds no
   * subscription id has one all the same: the id was written and then saved
   * over, or the return leg and the webhook never arrived. Stripe is asked
   * what the checkout made, and the answer is written down, so the next
   * reader finds it. An order that never had a checkout here has none, and
   * Stripe is not asked.
   *
   * @return string
   *   The subscription id, or '' for none.
   */
  private function subscriptionOf(OrderInterface $order): string {
    $subscription = (string) $order->getData(self::SUBSCRIPTION_KEY);
    $session = (string) $order->getData(self::SESSION_KEY);

    if ($subscription !== '' || $session === '' || $order->id() === NULL) {
      return $subscription;
    }

    try {
      $found = $this->stripe()->checkout->sessions->retrieve($session)->subscription ?? NULL;
    }
    catch (ApiErrorException) {
      return '';
    }

    $found = is_object($found) ? ($found->id ?? NULL) : $found;

    if (!is_string($found) || $found === '') {
      return '';
    }

    $this->orderLock->update($order, static function (OrderInterface $locked) use ($found): void {
      if ((string) $locked->getData(self::SUBSCRIPTION_KEY) === '') {
        $locked->setData(self::SUBSCRIPTION_KEY, $found);
      }
    });

    return (string) $order->getData(self::SUBSCRIPTION_KEY);
  }

  /**
   * {@inheritdoc}
   *
   * A pending invoice item on the subscription: Stripe adds it to the next
   * invoice it draws up for that subscription, and to no other.
   */
  public function addToNextInvoice(OrderInterface $order, Price $amount, string $description, string $idempotencyKey): bool {
    $subscription = $this->subscriptionOf($order);
    $customer = (string) $order->getData(self::CUSTOMER_KEY);

    if ($subscription === '' || $customer === '') {
      return FALSE;
    }

    try {
      $this->stripe()->invoiceItems->create([
        'customer' => $customer,
        'subscription' => $subscription,
        'amount' => $this->minorUnitsConverter->toMinorUnits($amount),
        'currency' => strtolower($amount->getCurrencyCode()),
        'description' => $description,
      ], ['idempotency_key' => $idempotencyKey]);
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe would not add %s to subscription %s: %s', (string) $amount, $subscription, $e->getMessage()), 0, $e);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function endTrialNow(OrderInterface $order): bool {
    $subscription = $this->subscriptionOf($order);

    if ($subscription === '') {
      return FALSE;
    }

    try {
      $this->stripe()->subscriptions->update($subscription, ['trial_end' => 'now', 'proration_behavior' => 'none']);
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe would not end the trial of subscription %s: %s', $subscription, $e->getMessage()), 0, $e);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * Matched line by line rather than replaced wholesale. Stripe prorates
   * every change, so deleting five items and adding five identical ones
   * would credit the customer for the rest of the period and charge them for
   * it again — a net of nothing on a good day, and on a bad one a refund and
   * a payment that both appear on a statement somebody has to explain.
   *
   * Identity is the item's own metadata where this code wrote it (the key is
   * Conventions::$lineMetadataKey), and the amount otherwise: the items
   * Checkout created carry no metadata of ours, and the first revision is
   * what gives them one.
   */
  public function reviseSubscription(OrderInterface $order, string $proration = 'create_prorations'): bool {
    if (!in_array($proration, ['create_prorations', 'always_invoice', 'none'], TRUE)) {
      throw new PaymentGatewayException('Unknown proration behavior.');
    }
    $subscription = $this->subscriptionOf($order);

    if ($subscription === '') {
      // Never handed to this gateway. Not an error: an order billed by bank
      // transfer is changed by changing the order, and there is nothing at
      // the far end that needs telling.
      return FALSE;
    }

    try {
      // The product is expanded because the name is half of what makes a
      // line that line. Without it two different things at the same price,
      // both 7.50, are indistinguishable, and the first revision swaps their
      // names on the customer's invoice while billing exactly the right
      // total.
      $remote = $this->stripe()->subscriptions->retrieve($subscription, [
        'expand' => ['items.data.price.product'],
      ]);
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf(
        'Stripe would not describe subscription %s: %s',
        $subscription,
        $e->getMessage(),
      ), 0, $e);
    }

    $wanted = [];
    foreach ($this->lineItems($order) as $line) {
      $wanted[self::signatureOf($line)] = $line;
    }

    $changes = [];

    foreach ($remote->items->data ?? [] as $item) {
      $signature = $this->matchFor($item, $wanted);
      $line = $signature === '' ? NULL : ($wanted[$signature] ?? NULL);

      if ($line === NULL) {
        // Nothing on the order pays for this any more: something removed,
        // an add-on given up, a size that changed and is now a different
        // line. Deleted rather than zeroed, because Stripe refuses a
        // recurring price of nothing.
        $changes[] = ['id' => $item->id, 'deleted' => TRUE];
        continue;
      }

      unset($wanted[$signature]);

      if ((int) $item->quantity !== (int) $line['quantity']) {
        // The same thing, more or fewer of them: two rather than one. A
        // quantity change is the cheapest revision there is and the only one
        // that leaves the invoice line where it was.
        $changes[] = [
          'id' => $item->id,
          'quantity' => (int) $line['quantity'],
          'metadata' => [$this->conventions->lineMetadataKey => $signature],
        ];
      }
      elseif (($item->metadata[$this->conventions->lineMetadataKey] ?? '') !== $signature) {
        // Right in every way but unlabelled — an item Checkout created. Named
        // now, so the next revision matches it without inferring anything
        // from its price.
        $changes[] = ['id' => $item->id, 'metadata' => [$this->conventions->lineMetadataKey => $signature]];
      }
    }

    foreach ($wanted as $signature => $line) {
      // A price of its own, made now. Checkout accepts a price described
      // inline — `price_data` with a `product_data` name — and a subscription
      // update does not: there, `price_data` wants a product that already
      // exists. Creating the price is how a line keeps its name without this
      // module keeping a catalogue in Stripe, which is the thing it has
      // avoided from the start: two catalogues that can disagree about what
      // somebody is paying for.
      try {
        $price = $this->stripe()->prices->create([
          'currency' => $line['price_data']['currency'],
          'unit_amount' => $line['price_data']['unit_amount'],
          'recurring' => $line['price_data']['recurring'],
          'product_data' => ['name' => $line['price_data']['product_data']['name']],
        ]);
      }
      catch (ApiErrorException $e) {
        throw new PaymentGatewayException(sprintf(
          'Stripe would not price "%s": %s',
          $line['price_data']['product_data']['name'] ?? '',
          $e->getMessage(),
        ), 0, $e);
      }

      $changes[] = [
        'price' => $price->id,
        'quantity' => $line['quantity'],
        'metadata' => [$this->conventions->lineMetadataKey => $signature],
      ];
    }

    if ($changes === []) {
      return FALSE;
    }

    try {
      $this->stripe()->subscriptions->update($subscription, [
        'items' => $changes,
        // An upgrade is paid at the change or not made at all.
        ...($proration === 'always_invoice' ? ['payment_behavior' => 'error_if_incomplete'] : []),
        // The customer is credited for what they gave up and charged for what
        // they took, on the next invoice. The alternative — billing the new
        // amount from the next period and nothing for the remainder of this
        // one — gives away what somebody is already using.
        'proration_behavior' => $proration,
      ]);
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf(
        'Stripe would not revise subscription %s: %s',
        $subscription,
        $e->getMessage(),
      ), 0, $e);
    }

    return TRUE;
  }

  /**
   * What makes one line the same line from one revision to the next.
   *
   * The price and the recurrence, plus what it is called. Not the quantity:
   * two of something is the same line as one of it, and treating a quantity
   * change as a different line is what turns "you added one more" into
   * a credit and a re-charge.
   *
   * @param array<string, mixed> $line
   *   A line as lineItems() builds it.
   */
  private static function signatureOf(array $line): string {
    $price = $line['price_data'] ?? [];
    $recurring = $price['recurring'] ?? [];

    return sprintf(
      '%s:%s:%s:%s:%s',
      $price['currency'] ?? '',
      $price['unit_amount'] ?? 0,
      $recurring['interval'] ?? '',
      $recurring['interval_count'] ?? 1,
      $price['product_data']['name'] ?? '',
    );
  }

  /**
   * Which wanted line, if any, this remote item already is.
   *
   * Two ways of asking, in order. An item this code has revised before says
   * so in its own metadata, and that is exact. An item Checkout created says
   * nothing, and is matched on what it costs *and what it is called* — the
   * name being the half that stops two different things at the same
   * price from being read as each other.
   *
   * An item that matches neither is one nothing on the order pays for any
   * more, which is the right answer for a line whose price changed: the old
   * one goes and the new one arrives beside it, prorated.
   *
   * @param object $item
   *   A Stripe subscription item.
   * @param array<string, array<string, mixed>> $wanted
   *   The lines still looking for a match, keyed by signature.
   *
   * @return string
   *   The signature it matches, or '' for an item nothing on the order pays
   *   for any more.
   */
  private function matchFor(object $item, array $wanted): string {
    $recorded = (string) ($item->metadata[$this->conventions->lineMetadataKey] ?? '');

    if ($recorded !== '') {
      return isset($wanted[$recorded]) ? $recorded : '';
    }

    $price = $item->price ?? NULL;
    $signature = sprintf(
      '%s:%s:%s:%s:%s',
      $price->currency ?? '',
      $price->unit_amount ?? 0,
      $price->recurring->interval ?? '',
      $price->recurring->interval_count ?? 1,
      is_object($price->product ?? NULL) ? (string) ($price->product->name ?? '') : '',
    );

    return isset($wanted[$signature]) ? $signature : '';
  }

  /**
   * {@inheritdoc}
   *
   * Deliberately does not mark the order paid. A subscription exists the
   * moment Checkout finishes; an *invoice* is paid later — seconds later for
   * a card, days later for SEPA, and possibly never. Treating the return leg
   * as payment is right for cards and wrong for bank debits, so what happens
   * here is only that the order learns which subscription pays for it.
   */
  public function onReturn(OrderInterface $order, Request $request): void {
    $sessionId = (string) $order->getData(self::SESSION_KEY);

    if ($sessionId === '') {
      throw new PaymentGatewayException('This order was never sent to Stripe.');
    }

    try {
      $session = $this->stripe()->checkout->sessions->retrieve($sessionId);
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe would not say what happened to that checkout: %s', $e->getMessage()), 0, $e);
    }

    // Open or expired: the customer did not finish it, whatever brought
    // them to this address.
    if (($session->status ?? NULL) !== 'complete') {
      throw new PaymentGatewayException('That checkout was not completed at Stripe.');
    }

    if (($session->subscription ?? NULL) === NULL) {
      throw new PaymentGatewayException('Stripe finished that checkout without creating a subscription.');
    }

    // Commerce places the order once this returns. A checkout opened for
    // other lines or another trial than the order now holds does not place
    // it: the customer is sent back to review what the order says. An order
    // already placed was compared by whichever leg placed it, and may have
    // been revised since.
    if ($order->getState()->getId() === 'draft' && !$this->openedFor($order, $session)) {
      throw new PaymentGatewayException('That checkout was not opened for what the order now holds. The order was not placed.');
    }

    // Learned here and nowhere else. Stripe makes the customer during the
    // checkout when it was given an address rather than an id, so this is
    // the first moment the id exists — and if it is not written down now,
    // the next purchase makes another one.
    $customer = $session->customer ?? NULL;

    $this->orderLock->update($order, static function (OrderInterface $locked) use ($session, $customer): void {
      $locked->setData(self::SUBSCRIPTION_KEY, (string) $session->subscription);
      if (is_string($customer) && $customer !== '') {
        $locked->setData(self::CUSTOMER_KEY, $customer);
      }
    });
  }

  /**
   * {@inheritdoc}
   *
   * The customer came back without paying. The checkout they left stays
   * payable at Stripe for a day unless it is closed, so it is closed. The
   * order goes on naming it: the next checkout to open closes it again if
   * Stripe did not answer this time, which costs nothing when it did.
   */
  public function onCancel(OrderInterface $order, Request $request): void {
    $this->closeEarlierSession($order);

    parent::onCancel($order, $request);
  }

  /**
   * {@inheritdoc}
   *
   * Stripe's own portal: payment methods and invoices only (see
   * portalConfiguration()), or the dashboard's default, as configured,
   * on pages Stripe keeps current with whatever the rules require this
   * quarter. What arrives back here is nothing at all, which is the point:
   * the session is opened, the customer goes, and this side has no card to
   * hold and no expiry to track.
   */
  public function billingPortalUrl(string $customer, string $returnUrl): ?string {
    if (trim($customer) === '') {
      return NULL;
    }

    if ($this->configuration['portal'] === 'dashboard') {
      try {
        // No configuration named: Stripe uses the account's default.
        return (string) $this->stripe()->billingPortal->sessions->create([
          'customer' => $customer,
          'return_url' => $returnUrl,
        ])->url;
      }
      catch (ApiErrorException $e) {
        // The commonest cause is a default configuration that has never
        // been saved in the Stripe dashboard, and Stripe's message says so.
        throw new PaymentGatewayException(sprintf('Stripe would not open a billing portal: %s', $e->getMessage()), 0, $e);
      }
    }

    $open = fn(bool $renew): string => (string) $this->stripe()->billingPortal->sessions->create([
      'customer' => $customer,
      'return_url' => $returnUrl,
      'configuration' => $this->portalConfiguration($renew),
    ])->url;

    try {
      try {
        return $open(FALSE);
      }
      catch (ApiErrorException $e) {
        // One retry: a configuration id kept from an earlier run may be gone
        // (a different Stripe account, a test-mode reset).
        if ($e->getStripeCode() !== 'resource_missing') {
          throw $e;
        }

        return $open(TRUE);
      }
    }
    catch (ApiErrorException $e) {
      // Surfaced rather than swallowed: a button that quietly does nothing
      // is the worst answer.
      throw new PaymentGatewayException(sprintf('Stripe would not open a billing portal: %s', $e->getMessage()), 0, $e);
    }
  }

  /**
   * The portal configuration that offers cards and invoices and nothing else.
   *
   * Built through the API, not left to the dashboard's default (which allows
   * cancelling and switching plans, and so a second path round the site's
   * own revision and its entitlements): payment-method update and invoice
   * history on; customer details, cancellation and plan changes off. Its id is
   * kept per mode, so one configuration serves every session.
   *
   * @param bool $renew
   *   TRUE to ignore the kept id and make a new configuration.
   */
  private function portalConfiguration(bool $renew = FALSE): string {
    $key = 'commerce_stripe_recurring.portal_configuration.' . $this->credentials->mode($this->configuration);
    $kept = $this->state->get($key);
    if (!$renew && is_string($kept) && $kept !== '') {
      return $kept;
    }

    try {
      $configuration = $this->stripe()->billingPortal->configurations->create([
        'business_profile' => ['headline' => 'Manage your payment method and invoices'],
        'features' => [
          'payment_method_update' => ['enabled' => TRUE],
          'invoice_history' => ['enabled' => TRUE],
          'customer_update' => ['enabled' => FALSE],
          'subscription_cancel' => ['enabled' => FALSE],
          'subscription_update' => ['enabled' => FALSE],
        ],
        'metadata' => ['purpose' => 'cards_and_invoices_only'],
      ]);
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe would not make the billing portal configuration: %s', $e->getMessage()), 0, $e);
    }
    $this->state->set($key, (string) $configuration->id);

    return (string) $configuration->id;
  }

  /**
   * {@inheritdoc}
   *
   * `mode=payment`: one charge, no subscription. The adjusted
   * (tax-inclusive) price is sent as one inline amount, like a subscription's
   * line. `customer_creation=always` makes Stripe keep a customer for a
   * buyer who had none, so a refund or a dispute can be matched to an
   * account afterwards. The metadata rides on the session and on the payment
   * intent.
   */
  public function createPaymentCheckoutSession(OrderInterface $order, string $returnUrl, string $cancelUrl): CheckoutSession {
    $lines = $this->lineItems($order, FALSE);
    // With what it was opened for, as a subscription's checkout has: the
    // site compares it before granting anything (openedFor()).
    $metadata = $this->metadata($order) + [
      $this->conventions->contentsMetadataKey => self::digestOf($lines, 0),
    ];

    $parameters = [
      'mode' => 'payment',
      'line_items' => $lines,
      'success_url' => $returnUrl,
      'cancel_url' => $cancelUrl,
      'client_reference_id' => (string) $order->id(),
      'metadata' => $metadata,
      'payment_intent_data' => ['metadata' => $metadata],
    ];

    $customer = (string) $order->getData(self::CUSTOMER_KEY);

    if ($customer !== '') {
      $parameters['customer'] = $customer;
    }
    else {
      $parameters['customer_creation'] = 'always';

      if (($email = $order->getEmail()) !== NULL && $email !== '') {
        $parameters['customer_email'] = $email;
      }
    }

    $this->closeEarlierSession($order);

    try {
      $session = $this->stripe()->checkout->sessions->create($parameters);
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe refused to open a payment checkout: %s', $e->getMessage()), 0, $e);
    }

    $this->orderLock->update($order, static function (OrderInterface $locked) use ($session): void {
      $locked->setData(self::SESSION_KEY, $session->id);
    });

    return $session;
  }

  /**
   * {@inheritdoc}
   *
   * A subscription made through the API rather than Checkout: there is no
   * card to collect, so there is no session. A product is made per line,
   * because a subscription item's inline price names an existing product.
   */
  public function createInvoicedSubscription(OrderInterface $order, string $customer, int $daysUntilDue): string {
    if ($customer === '' || $daysUntilDue < 1) {
      throw new PaymentGatewayException('Invoice terms need a Stripe customer and at least one day to pay.');
    }

    $items = [];
    $lines = $this->lineItems($order);

    try {
      foreach ($lines as $line) {
        $product = $this->stripe()->products->create(['name' => $line['price_data']['product_data']['name']]);
        $items[] = [
          'quantity' => $line['quantity'],
          'price_data' => [
            'currency' => $line['price_data']['currency'],
            'unit_amount' => $line['price_data']['unit_amount'],
            'recurring' => $line['price_data']['recurring'],
            'product' => $product->id,
          ],
        ];
      }

      $subscription = $this->stripe()->subscriptions->create([
        'customer' => $customer,
        'collection_method' => 'send_invoice',
        'days_until_due' => $daysUntilDue,
        'items' => $items,
        // With what it was started for, as a checkout has: an invoice paid
        // weeks later places the order only while the order still holds it.
        'metadata' => $this->metadata($order) + [
          $this->conventions->contentsMetadataKey => self::digestOf($lines, $this->trialOf($order)),
        ],
      ]);
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe refused to start an invoiced subscription: %s', $e->getMessage()), 0, $e);
    }

    $this->orderLock->update($order, static function (OrderInterface $locked) use ($subscription, $customer): void {
      $locked->setData(self::SUBSCRIPTION_KEY, (string) $subscription->id);
      $locked->setData(self::CUSTOMER_KEY, $customer);
    });

    return (string) $subscription->id;
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(string $paymentIntent, ?int $amountMinor = NULL, string $reason = 'requested_by_customer'): string {
    $parameters = ['payment_intent' => $paymentIntent, 'reason' => $reason];

    if ($amountMinor !== NULL) {
      $parameters['amount'] = $amountMinor;
    }

    try {
      $refund = $this->stripe()->refunds->create($parameters);
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe would not refund %s: %s', $paymentIntent, $e->getMessage()), 0, $e);
    }

    return (string) $refund->id;
  }

  /**
   * {@inheritdoc}
   *
   * Everything not already cancelled (Stripe's list leaves those out by
   * default), now, with no final invoice and no proration: closing an account
   * must not collect one last charge.
   */
  public function cancelCustomerSubscriptions(string $customer): int {
    if (trim($customer) === '') {
      return 0;
    }

    $cancelled = 0;

    try {
      foreach ($this->stripe()->subscriptions->all(['customer' => $customer, 'limit' => 100])->autoPagingIterator() as $subscription) {
        try {
          $this->stripe()->subscriptions->cancel($subscription->id, ['invoice_now' => FALSE, 'prorate' => FALSE]);
          $cancelled++;
        }
        catch (ApiErrorException $e) {
          // Gone between the list and the cancel is what was wanted.
          if ($e->getStripeCode() !== 'resource_missing') {
            throw $e;
          }
        }
      }
    }
    catch (ApiErrorException $e) {
      // A customer Stripe does not know has no subscription to cancel.
      if ($e->getStripeCode() === 'resource_missing') {
        return $cancelled;
      }

      throw new PaymentGatewayException(sprintf('Stripe would not cancel the subscriptions of %s: %s', $customer, $e->getMessage()), 0, $e);
    }

    return $cancelled;
  }

  /**
   * Names this site on an order's subscription, made before sites were named.
   *
   * So the invoices Stripe makes from it from now on carry the name, and
   * another site on the same account drops their events. Never overwrites a
   * name already there, never touches a subscription whose metadata names
   * another order, and leaves an ended subscription alone.
   *
   * Run where the orders were made only. A copy of the site's database holds
   * the same subscription ids, and would name its own copy on the original
   * site's subscriptions.
   *
   * @return bool
   *   Whether the subscription was named.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When Stripe would not answer or would not change it.
   */
  public function stampSite(OrderInterface $order): bool {
    $marker = $this->conventions->siteMetadata();
    $subscription = (string) $order->getData(self::SUBSCRIPTION_KEY);

    if ($marker === [] || $subscription === '') {
      return FALSE;
    }

    try {
      $current = $this->stripe()->subscriptions->retrieve($subscription);
    }
    catch (ApiErrorException $e) {
      if ($e->getStripeCode() === 'resource_missing') {
        return FALSE;
      }
      throw new PaymentGatewayException(sprintf('Stripe would not show subscription %s: %s', $subscription, $e->getMessage()), 0, $e);
    }

    $named = $current->metadata->{$this->conventions->siteMetadataKey} ?? NULL;
    $orderId = $current->metadata->order_id ?? NULL;

    if ((is_scalar($named) && (string) $named !== '')
      || (is_scalar($orderId) && (string) $orderId !== (string) $order->id())
      || ($current->status ?? '') === 'canceled') {
      return FALSE;
    }

    try {
      // Stripe merges metadata: the keys already there stay.
      $this->stripe()->subscriptions->update($subscription, ['metadata' => $marker]);
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe would not name the site on subscription %s: %s', $subscription, $e->getMessage()), 0, $e);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function createCustomer(string $email, array $metadata = [], ?string $testClock = NULL): string {
    $parameters = ['email' => $email];
    $metadata += $this->conventions->siteMetadata();

    if ($metadata !== []) {
      $parameters['metadata'] = $metadata;
    }

    if ($testClock !== NULL && $testClock !== '') {
      $this->requireTestMode();
      $parameters['test_clock'] = $testClock;
    }

    try {
      return (string) $this->stripe()->customers->create($parameters)->id;
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe would not create a customer: %s', $e->getMessage()), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createTestClock(int $frozenTime, string $name = ''): string {
    $this->requireTestMode();
    $parameters = ['frozen_time' => $frozenTime];

    if ($name !== '') {
      $parameters['name'] = $name;
    }

    try {
      return (string) $this->stripe()->testHelpers->testClocks->create($parameters)->id;
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe would not create a test clock: %s', $e->getMessage()), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function testClockFrozenTime(string $testClock): int {
    $this->requireTestMode();

    try {
      return (int) $this->stripe()->testHelpers->testClocks->retrieve($testClock)->frozen_time;
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe would not read test clock %s: %s', $testClock, $e->getMessage()), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Stripe lists newest first; a replay goes oldest first, so an
   * invoice is seen before the cancellation that follows it.
   */
  public function eventsSince(int $since): iterable {
    $events = [];

    try {
      foreach ($this->stripe()->events->all(['created' => ['gte' => $since], 'limit' => 100])->autoPagingIterator() as $event) {
        $events[] = $event;
      }
    }
    catch (ApiErrorException $e) {
      throw new PaymentGatewayException(sprintf('Stripe would not list its events: %s', $e->getMessage()), 0, $e);
    }

    return array_reverse($events);
  }

  /**
   * Refuses a test-only feature unless the keys are test keys.
   *
   * Test clocks exist only in test mode, and a live key must never be asked
   * for one.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the mode is not test.
   */
  private function requireTestMode(): void {
    if ($this->credentials->mode($this->configuration) !== 'test') {
      throw new PaymentGatewayException('Test clocks exist in Stripe test mode only.');
    }
  }

}
