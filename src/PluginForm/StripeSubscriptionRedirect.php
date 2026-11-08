<?php

declare(strict_types=1);

namespace Drupal\commerce_stripe_recurring\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription;

/**
 * Sends the customer to Stripe Checkout.
 *
 * The whole of the off-site form, and there is very little of it on purpose.
 * `PaymentOffsiteForm` is abstract and expects every gateway to bring a
 * subclass that opens whatever the gateway needs opening and then calls
 * `buildRedirectForm()`; naming the abstract class itself in the plugin
 * definition gets as far as the payment step and then cannot be
 * instantiated.
 *
 * A GET redirect rather than a posted form. Stripe hands back a URL that
 * already carries the session, so there is nothing to post and nothing for a
 * customer to see in between — `buildRedirectForm()` with REDIRECT_GET throws
 * `NeedsRedirectException` and the browser simply goes.
 */
final class StripeSubscriptionRedirect extends PaymentOffsiteForm {

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The plugin form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array<string, mixed>
   *   The form, though in practice the redirect happens first.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    // `$this->entity` is typed as anything carrying a payment gateway — a
    // payment or a stored payment method — and only a payment has an order.
    // This form is built for one, so the narrowing is a statement of that
    // rather than a doubt about it.
    $payment = $this->entity;
    $gateway = $payment->getPaymentGateway()?->getPlugin();
    $order = $payment instanceof PaymentInterface ? $payment->getOrder() : NULL;

    if (!$gateway instanceof StripeSubscription || $order === NULL) {
      // The form is only ever built for this gateway, so this is a
      // configuration that has been changed underneath a checkout somebody
      // is halfway through. Refused rather than redirected somewhere wrong.
      throw new PaymentGatewayException('This order is not being paid through a Stripe subscription.');
    }

    $session = $gateway->createCheckoutSession(
      $order,
      (string) $form['#return_url'],
      (string) $form['#cancel_url'],
    );

    $url = (string) ($session->url ?? '');

    if ($url === '') {
      // Stripe opened a session and gave nowhere to send anybody. Worth
      // saying plainly: the alternative is a checkout that appears to work
      // and stops on a blank page.
      throw new PaymentGatewayException('Stripe opened a checkout session without a URL to send the customer to.');
    }

    return $this->buildRedirectForm($form, $form_state, $url, [], self::REDIRECT_GET);
  }

}
