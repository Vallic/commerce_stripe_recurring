<?php

declare(strict_types=1);

namespace Drupal\commerce_stripe_recurring;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityInterface;

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
  ];

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
   * Builds the conventions from the container parameter.
   *
   * @param array<string, mixed> $conventions
   *   The site's values. Keys it leaves out take their defaults.
   */
  public function __construct(array $conventions = []) {
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
