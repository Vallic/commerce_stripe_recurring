<?php

declare(strict_types=1);

namespace Drupal\commerce_stripe_recurring;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Site\Settings;

/**
 * The names a site chooses: what this module writes, and which fields it reads.
 *
 * Read from the `commerce_stripe_recurring.conventions` container parameter.
 * Every key has a neutral default, and a site overrides only the keys it
 * needs, in its `services.yml` or from a service provider. Most of these
 * names end up stored somewhere, in order data or in Stripe's metadata, so a
 * site that already has data keeps the names it has been writing.
 *
 * - `line_metadata_key`: the metadata key that labels each Stripe
 *   subscription item with the order line it bills.
 * - `lock_prefix`: the prefix of the lock name every write to an order takes.
 *   A site whose own code locks orders uses the same prefix, so the two wait
 *   for each other.
 * - `duplicate_key`: the order data key of a subscription the site cancelled
 *   as a duplicate. Such an order is never placed by an invoice.
 * - `owner_field`: the order field naming who the order belongs to (a user,
 *   a team, an account). Empty: none.
 * - `owner_metadata_key`: the metadata key that carries the owner to Stripe.
 *   An event whose metadata names another owner than the order's is ignored.
 * - `owner_value`: `uuid` or `id`, which of the owner's identifiers is sent.
 * - `renewal_skip_fields`: order fields a renewal order must not copy from
 *   the subscription order, beyond Commerce's own.
 * - `pending_item_field`: an order item boolean field. An item with it set
 *   waits for a change Stripe has not agreed to yet and is not billed.
 * - `closed_item_field`: an order item field. An item with it set is closed
 *   and not billed.
 * - `payment_intent_field`: an order field where the payment intent of the
 *   invoice an order records is written, so a refund or a dispute finds it.
 * - `profile_copy_field`: a boolean profile field. Where the billing profile
 *   has it, a renewal gets its own copy of the profile, marked with it.
 * - `site_metadata_key`: the metadata key that carries which site made an
 *   object (siteMetadata()).
 * - `contents_metadata_key`: the metadata key that carries what a checkout
 *   was opened for: the lines and the trial, as one value
 *   (StripeSubscription::contentsOf()).
 * - `monthly_prices`: whether the order is priced for one month. Off, a line
 *   is sent at its adjusted unit price, whatever the months between charges.
 *   On, Stripe is sent the amount for the whole period: each line with its
 *   share of the order's own adjustments, times the months between charges.
 *
 * The site itself is not a convention but a setting, since each copy of a
 * site needs its own: `$settings['commerce_stripe_recurring_site']` in
 * settings.php.
 */
final readonly class Conventions {

  /**
   * The defaults, which a site's parameter is merged into.
   *
   * @var array<string, mixed>
   */
  public const array DEFAULTS = [
    'line_metadata_key' => 'csr_line',
    'lock_prefix' => 'commerce_stripe_recurring_order:',
    'duplicate_key' => 'stripe_duplicate_of',
    'owner_field' => '',
    'owner_metadata_key' => '',
    'owner_value' => 'uuid',
    'renewal_skip_fields' => [],
    'pending_item_field' => '',
    'closed_item_field' => '',
    'payment_intent_field' => '',
    'profile_copy_field' => '',
    'site_metadata_key' => 'csr_site',
    'contents_metadata_key' => 'csr_contents',
    'monthly_prices' => FALSE,
  ];

  /**
   * The setting naming this installation of the site to Stripe.
   */
  public const string SITE_SETTING = 'commerce_stripe_recurring_site';

  /**
   * The metadata key that labels a Stripe subscription item.
   */
  public string $lineMetadataKey;

  /**
   * The prefix of an order's lock name.
   */
  public string $lockPrefix;

  /**
   * The order data key of a subscription cancelled as a duplicate.
   */
  public string $duplicateKey;

  /**
   * The order field naming the owner, or ''.
   */
  public string $ownerField;

  /**
   * The metadata key that carries the owner, or ''.
   */
  public string $ownerMetadataKey;

  /**
   * Whether the owner is sent by `uuid` or by `id`.
   */
  public string $ownerValue;

  /**
   * Order fields a renewal does not copy.
   *
   * @var array<int, string>
   */
  public array $renewalSkipFields;

  /**
   * The order item field marking a pending item, or ''.
   */
  public string $pendingItemField;

  /**
   * The order item field marking a closed item, or ''.
   */
  public string $closedItemField;

  /**
   * The order field holding the payment intent, or ''.
   */
  public string $paymentIntentField;

  /**
   * The profile field marking an order's own copy, or ''.
   */
  public string $profileCopyField;

  /**
   * The metadata key that carries which site made an object.
   */
  public string $siteMetadataKey;

  /**
   * The metadata key that carries what a checkout was opened for.
   */
  public string $contentsMetadataKey;

  /**
   * Whether the order's prices are for one month.
   */
  public bool $monthlyPrices;

  /**
   * Which installation of the site this is, or '' when the site set none.
   */
  public string $site;

  /**
   * Builds the conventions from the container parameter.
   *
   * @param array<string, mixed> $conventions
   *   The site's values. Keys it leaves out take their defaults.
   * @param string $site
   *   Which installation of the site this is; see SITE_SETTING.
   */
  public function __construct(array $conventions = [], string $site = '') {
    $values = $conventions + self::DEFAULTS;

    $this->lineMetadataKey = (string) $values['line_metadata_key'] ?: self::DEFAULTS['line_metadata_key'];
    $this->lockPrefix = (string) $values['lock_prefix'] ?: self::DEFAULTS['lock_prefix'];
    $this->duplicateKey = (string) $values['duplicate_key'] ?: self::DEFAULTS['duplicate_key'];
    $this->ownerField = (string) $values['owner_field'];
    $this->ownerMetadataKey = (string) $values['owner_metadata_key'];
    $this->ownerValue = $values['owner_value'] === 'id' ? 'id' : 'uuid';
    $this->renewalSkipFields = array_values(array_map('strval', (array) $values['renewal_skip_fields']));
    $this->pendingItemField = (string) $values['pending_item_field'];
    $this->closedItemField = (string) $values['closed_item_field'];
    $this->paymentIntentField = (string) $values['payment_intent_field'];
    $this->profileCopyField = (string) $values['profile_copy_field'];
    $this->siteMetadataKey = (string) $values['site_metadata_key'] ?: self::DEFAULTS['site_metadata_key'];
    $this->contentsMetadataKey = (string) $values['contents_metadata_key'] ?: self::DEFAULTS['contents_metadata_key'];
    $this->monthlyPrices = filter_var($values['monthly_prices'], FILTER_VALIDATE_BOOLEAN);
    $this->site = trim($site);
  }

  /**
   * The conventions, with the site named in settings.php.
   *
   * @param array<string, mixed> $conventions
   *   The site's values, from the container parameter.
   * @param \Drupal\Core\Site\Settings $settings
   *   The settings.
   */
  public static function fromSettings(array $conventions, Settings $settings): self {
    return new self($conventions, (string) $settings->get(self::SITE_SETTING, ''));
  }

  /**
   * The metadata that says this site made an object, or none.
   *
   * Stripe sends every event of an account to every endpoint on it, each
   * signed with that endpoint's own secret, so a site sharing the account
   * with others verifies their events as readily as its own. Its order ids
   * are plain numbers that another site's orders have too. This is what
   * tells them apart: every object made here carries the site's name.
   *
   * @return array<string, string>
   *   The key and the site, or nothing when no site is set.
   */
  public function siteMetadata(): array {
    return $this->site === '' ? [] : [$this->siteMetadataKey => $this->site];
  }

  /**
   * Whether a Stripe object was made by another site.
   *
   * By the site its metadata names, in the three places it surfaces: an
   * object's own metadata (a session, a subscription, a payment intent), and
   * an invoice's parent (`parent.subscription_details`, or
   * `subscription_details` before the 2025 API versions).
   *
   * An object naming no site is not another's: it was made before sites
   * were named, or by a site that names none, and the other checks decide.
   * One naming a site while this one names none is another's, since nothing
   * here wrote it.
   */
  public function isForeign(object $object): bool {
    $key = $this->siteMetadataKey;
    $claimed = $object->metadata->{$key}
    ?? $object->parent->subscription_details->metadata->{$key}
    ?? $object->subscription_details->metadata->{$key}
    ?? NULL;

    return is_scalar($claimed) && (string) $claimed !== '' && (string) $claimed !== $this->site;
  }

  /**
   * What a Stripe object says its checkout was opened for, or ''.
   *
   * Read where isForeign() reads the site: an object's own metadata (a
   * session, a subscription, a payment intent), and an invoice's parent, as
   * the subscription had it when the invoice was made.
   */
  public function contentsClaimedBy(object $object): string {
    $key = $this->contentsMetadataKey;
    $claimed = $object->metadata->{$key}
    ?? $object->parent->subscription_details->metadata->{$key}
    ?? $object->subscription_details->metadata->{$key}
    ?? NULL;

    return is_scalar($claimed) ? (string) $claimed : '';
  }

  /**
   * Whether an order item is billed: neither pending nor closed.
   *
   * Decided by the item's own fields, never by a clock. An item without the
   * fields is always billed.
   */
  public function billsItem(OrderItemInterface $item): bool {
    if ($this->pendingItemField !== '' && $item->hasField($this->pendingItemField)
      && (bool) $item->get($this->pendingItemField)->value) {
      return FALSE;
    }

    if ($this->closedItemField !== '' && $item->hasField($this->closedItemField)
      && !$item->get($this->closedItemField)->isEmpty()) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * The owner identifier an order sends to Stripe, or ''.
   */
  public function ownerOf(OrderInterface $order): string {
    if ($this->ownerField === '' || !$order->hasField($this->ownerField) || $order->get($this->ownerField)->isEmpty()) {
      return '';
    }

    $field = $order->get($this->ownerField);

    if ($this->ownerValue === 'id') {
      return (string) ($field->target_id ?? '');
    }

    $owner = $field->entity ?? NULL;

    return $owner instanceof EntityInterface ? (string) $owner->uuid() : '';
  }

}
