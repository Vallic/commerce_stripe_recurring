# Commerce Stripe Recurring

A Stripe Billing payment gateway for Drupal Commerce. The customer pays on Stripe Checkout, Stripe runs the subscription, and the Commerce order follows what Stripe reports: an order is paid on `invoice.paid` and on nothing else, and every later period gets an order of its own.

The gateway plugin is `stripe_subscription`. Nothing sensitive reaches the site: Stripe holds the payment method and any mandate, and the order keeps the subscription's id.

## What it does

- **Checkout.** `mode=subscription` for a subscription, `mode=payment` for a one-off payment (`createPaymentCheckoutSession()`). Lines are priced inline from the order's adjusted, tax-inclusive unit prices, so there is no catalogue to keep in Stripe. Months between charges and trial days come from the order or from the gateway's settings. A line's price is the amount of one charge, unless the site prices its orders [for one month](#orders-priced-for-one-month).
- **One checkout, one order as shown.** An order has one open checkout at a time, and a checkout places the order only while the order still holds [what it was opened for](#a-checkout-places-what-it-was-opened-for).
- **Revisions.** `reviseSubscription()` makes the Stripe subscription bill what the order now says. Lines are matched one by one, so changing a quantity does not remove and re-add the line. Proration is `create_prorations`, `always_invoice` or `none`.
- **Charges in arrears.** `addToNextInvoice()` adds a one-off amount to the subscription's next invoice, with an idempotency key.
- **Cancellation.** At a date (`cancelSubscriptionAt()`), now (`cancelSubscriptionNow()`), undone (`resumeSubscription()`), or every subscription of a customer at once (`cancelCustomerSubscriptions()`).
- **Renewal orders.** Each later invoice becomes a placed and paid order (`RenewalOrders`), numbered with Stripe's invoice number and holding the lines Stripe charged.
- **Events.** `OrderEvents::handle()` applies `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed` and `customer.subscription.deleted` to their order. Every write to an order takes a lock (`OrderLock`) and reloads the order inside it. Recording a payment is idempotent, keyed on the Stripe invoice id.
- **Customer portal.** `billingPortalUrl()` opens Stripe's portal: either a configuration this module keeps, offering payment methods and invoices only, or the account's default from the Stripe dashboard.
- **Other calls:** a payment method's fingerprint (to refuse a second free trial to the same card), ending a trial now, refunds, invoiced subscriptions (`send_invoice`), customers, test clocks, and listing past events to replay them.

Other modules use the gateway through `RecurringGatewayInterface`.

## Requirements

- Drupal 11, Commerce 3 (Order, Payment, Price).
- `stripe/stripe-php` ^21.3. The gateway pins the Stripe API version to `2025-09-30.clover` (`StripeSubscription::API_VERSION`), so a library upgrade does not also change the wire format.

## Set up

1. Enable the module and add a payment gateway with the **Stripe subscription** plugin.
2. Enter the secret key and the publishable key. The mode follows the key: `sk_test_`/`rk_test_` is test, `sk_live_`/`rk_live_` is live, and anything else is refused before it reaches Stripe.
3. Receive Stripe's events, in one of two ways:
   - Enable **Commerce Stripe Recurring Webhook** and point a Stripe webhook endpoint at `/stripe/subscription/{gateway id}/webhook`. Enter its signing secret (`whsec_…`) on the gateway. Subscribe to at least `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed` and `customer.subscription.deleted`.
   - Or receive events at an endpoint of your own: check the signature, store the event if you want to, and pass it to `commerce_stripe_recurring.order_events`'s `handle()`.

## Keys from the environment

The gateway gets its keys from the `commerce_stripe_recurring.credentials` service (`StripeCredentialsInterface`). The default reads the gateway's own settings. To keep the keys out of configuration, decorate the service in your own module and return the keys from `settings.php` or the environment. Your implementation also decides which mode is allowed on which environment.

## Conventions

Some names are the site's to choose: what this module writes into order data and Stripe metadata, and which of the site's fields it reads. They are keys of the `commerce_stripe_recurring.conventions` container parameter. A site sets only the keys it needs, and the rest keep their defaults:

| Key | Default | What it is |
| --- | --- | --- |
| `line_metadata_key` | `csr_line` | The Stripe metadata key that labels each subscription item with the order line it bills. |
| `lock_prefix` | `commerce_stripe_recurring_order:` | The lock name prefix for writes to an order. Your own code can lock the same orders through `OrderLock`, or by using this prefix. |
| `duplicate_key` | `stripe_duplicate_of` | The order data key marking a subscription the site cancelled as a duplicate. An invoice never places such an order. |
| `owner_field` | (none) | The order field naming whose order it is: a user, a team, an account. |
| `owner_metadata_key` | (none) | The metadata key that carries the owner to Stripe. An event naming a different owner from the order's is ignored. |
| `owner_value` | `uuid` | `uuid` or `id`: which identifier of the owner is sent. |
| `renewal_skip_fields` | `[]` | Order fields a renewal order does not copy from the subscription order, on top of Commerce's own. |
| `pending_item_field` | (none) | A boolean order item field. Items with it set wait for a change Stripe has not agreed to yet, and are not billed. |
| `closed_item_field` | (none) | An order item field. Items with a value in it are closed and not billed. |
| `payment_intent_field` | (none) | An order field where the order's payment intent is written, so a refund or a dispute can find the order. |
| `profile_copy_field` | (none) | A boolean profile field. Where the billing profile has it, each renewal gets its own copy of the profile, marked with it. |
| `site_metadata_key` | `csr_site` | The metadata key that carries which site made a Stripe object. See [Several sites on one Stripe account](#several-sites-on-one-stripe-account). |
| `contents_metadata_key` | `csr_contents` | The metadata key that carries what a checkout was opened for. See [A checkout places what it was opened for](#a-checkout-places-what-it-was-opened-for). |
| `monthly_prices` | `false` | Whether the order is priced for one month. See [Orders priced for one month](#orders-priced-for-one-month). |

Most of these names end up stored in order data or in Stripe, so change them only before any data exists. A site that already has data keeps the names it has been writing.

Set them in `sites/default/services.yml`:

```yaml
parameters:
  commerce_stripe_recurring.conventions:
    line_metadata_key: 'shop_line'
    owner_field: 'uid'
    owner_metadata_key: 'customer_uuid'
```

or from a service provider in your own module:

```php
final class MyModuleServiceProvider extends ServiceProviderBase {

  public function alter(ContainerBuilder $container): void {
    $container->setParameter('commerce_stripe_recurring.conventions', [
      'owner_field' => 'team',
      'owner_metadata_key' => 'team_id',
      'owner_value' => 'id',
    ]);
  }

}
```

## Orders priced for one month

By default a line's price is the amount of one charge. An order of 30.00 billed every three months collects 30.00 a quarter, and an adjustment on the order itself, as opposed to on a line, is not sent.

A site that prices its orders for one month, whatever the period, says so:

```yaml
parameters:
  commerce_stripe_recurring.conventions:
    monthly_prices: true
```

Stripe is then sent the amount for the whole period, so that a period collects the order's total times its months:

- **Months.** Each line's adjusted unit price is multiplied by the months between charges: `INTERVAL_KEY` on the order, or the gateway's setting. 30.00 a month is 90.00 a quarter and 360.00 a year.
- **The order's own adjustments.** Stripe is given lines only, so an adjustment on the order, such as a discount for paying ahead, is shared over the lines in proportion to what each costs. 30.00 a month with 1.50 off is 85.50 a quarter. Adjustments on a line are in its adjusted unit price already, with or without this key.
- **To the cent.** What rounding leaves goes to a line of one unit. An order whose every line has a quantity above one can come out a few cents either side, since such a line only moves by its quantity.
- **One-off payments** have no months: they take the order's adjustments and nothing else.
- **Nothing to bill.** An order whose adjustments take off as much as its lines cost is refused, as an order of free lines is.

The checkout, the invoiced subscription, `reviseSubscription()` and `revisionApplied()` all read the same lines, so they agree.

Three things to know before switching it on:

- **An adjustment recurs.** Whatever is on the subscription order as an order-level adjustment comes off, or goes on, every period. A credit to be given once does not belong on the subscription order.
- **Subscriptions already running** at a longer period carry one month's amount per period. Their lines no longer match the order's, so the next `reviseSubscription()` replaces every line. Choose its proration deliberately: `none` bills the right amount from the next renewal, the default credits and charges the rest of the current period.
- **The order's total stays one month's.** The first invoice of a longer period pays more than the order's total, and the order's balance shows it.

## A checkout places what it was opened for

A checkout shows the customer lines and a trial, and bills those. An order can change while its checkout is open, or after the customer has left it. So:

- **What it was opened for travels with it.** Every Checkout session and every subscription made here carries one value for the lines Stripe was sent (currency, amount, quantity, name and recurrence of each) and the days of trial, under `contents_metadata_key`. Stripe copies a subscription's onto its invoices. `contentsOf()` on the gateway computes the same value from an order.
- **A draft order is placed only when they agree.** On the return leg, on `checkout.session.completed` and on `invoice.paid`, the value the checkout carries is compared with the order as it stands. Where they differ, the order stays a draft, no subscription is recorded on it and no payment. The customer who came back is sent to the previous checkout step.
- **What was held back is listed.** The subscription's id is added to the order's `StripeSubscription::MISMATCH_KEY` data, a list, and an error is logged. If that subscription collected money, cancel and refund it: nothing here does.
- **Placed orders are not compared.** They change afterwards as a matter of course (`reviseSubscription()`).
- **One open checkout.** Opening a checkout expires the one the order was sent to before, and so does the customer coming back through the cancel address.
- **The return leg needs a finished checkout.** A session Stripe does not report as `complete` records nothing.
- **The order names its gateway.** The comparison is made by the gateway plugin in the order's `payment_gateway`, as Commerce's checkout sets it. A draft order whose event carries the value and that names no such gateway is held back too.
- **Subscriptions made some other way** carry no value. Their events are applied as before, unless the order was sent to a checkout (`SESSION_KEY`).
- **One-off payments** carry the value too. Granting what was paid for is the site's, and so is the comparison: `openedFor($order, $session)` on the gateway.

## Several sites on one Stripe account

Stripe sends every event of an account to every webhook endpoint on it, and signs each with that endpoint's own secret. So a site sharing the account with others verifies their events as readily as its own, and their order ids are numbers its own orders have too. Name each site, and each copy of it, in its `settings.php`:

```php
$settings['commerce_stripe_recurring_site'] = 'shop-production';
```

- **What is named.** Every Checkout session, subscription, payment intent and customer the site makes carries the name in its metadata, under `site_metadata_key`. The invoices Stripe makes from a subscription carry it too.
- **What is dropped.** An event naming another site is acknowledged and dropped. It changes no order, and the site's own `WebhookEvent` subscribers never see it. A site that names none drops every event that names a site.
- **What still passes.** An event naming no site, such as one about a subscription made before the site was named, goes through the other checks: the order id, the owner, and the subscription and customer the order records. So does an event about an object with no metadata, such as a dispute; a subscriber matches those by its own records.
- **Copies need their own name.** A copy of the database (staging, a laptop) on the same account holds the same order ids, owners and subscription ids, so the other checks pass there. Only a name of its own tells it apart. Don't change a site's name once objects carry it.

Subscriptions made before the site was named don't carry the name. Once `settings.php` names the site, name it on them, where the orders were made:

```sh
drush commerce-stripe-recurring:stamp-site
```

It asks first, never replaces a name already there, and leaves ended subscriptions and those naming another order alone. Never run it on a copy of the database: the copy holds the same subscription ids, and would put its own name on the original site's subscriptions.

## Order data

The order records what the gateway knows, under keys the site can read:

- `StripeSubscription::SUBSCRIPTION_KEY`, `CUSTOMER_KEY` and `SESSION_KEY`: the subscription, customer and Checkout session ids.
- `ENDED_KEY` and `ENDED_REASON_KEY`: when the subscription ended, and Stripe's `cancellation_details.reason`.
- `INTERVAL_KEY` and `TRIAL_KEY`, which the site writes: months between charges and trial days for this order.
- `MISMATCH_KEY`: the subscriptions of checkouts that were opened for something else than the order held when they were paid, as a list of ids. See [A checkout places what it was opened for](#a-checkout-places-what-it-was-opened-for).
- `RenewalOrders::INVOICE_KEY`, `RENEWS_KEY`, `INVOICE_URL_KEY`, `PERIOD_START_KEY` and `PERIOD_END_KEY`: the invoice an order records, the order it renews, Stripe's hosted invoice, and the period it covers.

## Other Stripe events

The webhook submodule dispatches `WebhookEvent` (`commerce_stripe_recurring_webhook.event`) for every verified event, after this module has applied it. A subscriber can act on event types this module ignores, such as identity verification, disputes or one-off payments. If it does, it calls `setHandled()`, and the endpoint reports the event as handled.

## Upgrading

- **Checkouts open during the upgrade.** A session opened by an earlier version does not say what it was opened for, and does not place a draft order. Sessions live a day at most. An order left in draft by one is placed by hand, or by the customer checking out again.
- **Tests of your own** that hand `OrderEvents` an event for a draft order holding a `SESSION_KEY` need the event to carry the value `createCheckoutSession()` sent.
- **Prices.** Nothing changes unless `monthly_prices` is set.

## Tests

The kernel tests run with the core test runner:

```sh
vendor/bin/phpunit -c web/core web/modules/contrib/commerce_stripe_recurring
```

- `StripeMockGatewayTest` sends real requests to [stripe-mock](https://github.com/stripe/stripe-mock). It reads stripe-mock's URL from `STRIPE_MOCK_URL`, defaulting to `http://stripe-mock:12111`, and skips when that is not set and nothing answers there. In CI it runs as a service.
- `StripeTestModeTest` talks to real Stripe test mode. It runs only with a sandbox key in `STRIPE_TEST_SECRET_KEY`, and refuses any key that is not a test key.
- The other tests use an in-process fake of the Stripe client and need nothing else.

## Maintainers

Developed and maintained by [Vallic](https://vallic.com), a Drupal development
company and the team behind Vallic Cloud, Drupal hosting.
