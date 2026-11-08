<?php

declare(strict_types=1);

namespace Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentMethodType;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_payment\Attribute\CommercePaymentMethodType;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType\PaymentMethodTypeBase;

/**
 * Whatever Stripe is charging, held by Stripe rather than here.
 *
 * A gateway definition has to name a payment method type, so this says the
 * true thing rather than borrowing `credit_card` and implying a card. The
 * customer may be paying by card, by direct debit, or by one of a dozen
 * local instruments; the mandate and the details are Stripe's, and what this
 * side keeps is which subscription is paying.
 *
 * It carries no fields of its own, deliberately. The off-site flow creates no
 * payment method at all — there is nothing to put in them — and a field that
 * is never written still gets a table, still has to be installed, and still
 * has to be deleted when somebody uninstalls a module. An earlier version of
 * this class declared one for a label nobody reads, and the first thing it
 * did was break an unrelated module's uninstall.
 */
#[CommercePaymentMethodType(
  id: 'stripe_subscription',
  label: new TranslatableMarkup('Stripe subscription'),
)]
final class StripeSubscription extends PaymentMethodTypeBase {

  /**
   * {@inheritdoc}
   */
  public function buildLabel(PaymentMethodInterface $payment_method): string {
    return (string) $this->t('Billed by Stripe');
  }

}
