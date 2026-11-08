# Commerce Stripe Recurring

A Stripe Billing payment gateway for Drupal Commerce. The customer pays on Stripe Checkout, Stripe runs the subscription, and the Commerce order follows what Stripe reports: an order is paid on `invoice.paid` and on nothing else, and every later period gets an order of its own.

The gateway plugin is `stripe_subscription`. Nothing sensitive reaches the site: Stripe holds the payment method and any mandate, and the order keeps the subscription's id.

## What it does

- **Checkout.** `mode=subscription` for a subscription, `mode=payment` for a one-off payment (`createPaymentCheckoutSession()`). Lines are priced inline from the order's adjusted, tax-inclusive unit prices, so there is no catalogue to keep in Stripe. Months between charges and trial days come from the order or from the gateway's settings.
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

## Order data

The order records what the gateway knows, under keys the site can read:

- `StripeSubscription::SUBSCRIPTION_KEY`, `CUSTOMER_KEY` and `SESSION_KEY`: the subscription, customer and Checkout session ids.
- `ENDED_KEY` and `ENDED_REASON_KEY`: when the subscription ended, and Stripe's `cancellation_details.reason`.
- `INTERVAL_KEY` and `TRIAL_KEY`, which the site writes: months between charges and trial days for this order.
- `RenewalOrders::INVOICE_KEY`, `RENEWS_KEY`, `INVOICE_URL_KEY`, `PERIOD_START_KEY` and `PERIOD_END_KEY`: the invoice an order records, the order it renews, Stripe's hosted invoice, and the period it covers.

## Other Stripe events

The webhook submodule dispatches `WebhookEvent` (`commerce_stripe_recurring_webhook.event`) for every verified event, after this module has applied it. A subscriber can act on event types this module ignores, such as identity verification, disputes or one-off payments. If it does, it calls `setHandled()`, and the endpoint reports the event as handled.

## Tests

The kernel tests run with the core test runner:

```sh
vendor/bin/phpunit -c web/core web/modules/contrib/commerce_stripe_recurring
```

- `StripeMockGatewayTest` sends real requests to [stripe-mock](https://github.com/stripe/stripe-mock). It reads stripe-mock's URL from `STRIPE_MOCK_URL`, defaulting to `http://stripe-mock:12111`, and skips when that is not set and nothing answers there. In CI it runs as a service.
- `StripeTestModeTest` talks to real Stripe test mode. It runs only with a sandbox key in `STRIPE_TEST_SECRET_KEY`, and refuses any key that is not a test key.
- The other tests use an in-process fake of the Stripe client and need nothing else.
