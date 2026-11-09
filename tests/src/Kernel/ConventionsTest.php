<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_recurring\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_stripe_recurring\Conventions;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Stripe\StripeObject;

/**
 * The names a site chooses, and the fields they point at.
 */
#[CoversClass(Conventions::class)]
#[Group('commerce_stripe_recurring')]
#[RunTestsInSeparateProcesses]
final class ConventionsTest extends KernelTestBase {

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
    'commerce_stripe_recurring_test', 'commerce_stripe_recurring',
  ];

  /**
   * {@inheritdoc}
   *
   * Only some keys: the rest keep their defaults.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->setParameter('commerce_stripe_recurring.conventions', [
      'line_metadata_key' => 'shop_line',
      'owner_field' => 'field_owner',
      'owner_metadata_key' => 'owner_uuid',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('profile');

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
    User::create(['uid' => 1, 'name' => 'owner'])->save();

    $fields = [
      ['commerce_order_item', 'field_pending', 'boolean', []],
      ['commerce_order_item', 'field_closed', 'timestamp', []],
      ['commerce_order', 'field_owner', 'entity_reference', ['target_type' => 'user']],
    ];
    foreach ($fields as [$entityType, $name, $type, $settings]) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'type' => $type,
        'settings' => $settings,
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => $entityType,
        'bundle' => 'default',
        'label' => $name,
      ])->save();
    }
  }

  /**
   * The site's keys are merged into the defaults, from the container.
   */
  public function testTheSitesKeysAreMergedIntoTheDefaults(): void {
    $conventions = $this->container->get('commerce_stripe_recurring.conventions');

    $this->assertSame('shop_line', $conventions->lineMetadataKey);
    $this->assertSame('field_owner', $conventions->ownerField);
    $this->assertSame(Conventions::DEFAULTS['lock_prefix'], $conventions->lockPrefix);
    $this->assertSame(Conventions::DEFAULTS['duplicate_key'], $conventions->duplicateKey);
    $this->assertSame([], $conventions->renewalSkipFields);

    // An empty value for a name that is written somewhere is no name.
    $this->assertSame(Conventions::DEFAULTS['line_metadata_key'], (new Conventions(['line_metadata_key' => '']))->lineMetadataKey);
    $this->assertSame('uuid', (new Conventions(['owner_value' => 'anything']))->ownerValue);
  }

  /**
   * An item is billed unless the fields the site names say otherwise.
   */
  public function testItemsAreBilledUnlessPendingOrClosed(): void {
    $named = new Conventions(['pending_item_field' => 'field_pending', 'closed_item_field' => 'field_closed']);
    $unnamed = new Conventions();

    $open = OrderItem::create(['type' => 'default', 'quantity' => 1]);
    $pending = OrderItem::create(['type' => 'default', 'quantity' => 1, 'field_pending' => TRUE]);
    $closed = OrderItem::create(['type' => 'default', 'quantity' => 1, 'field_closed' => 1_700_000_000]);

    $this->assertTrue($named->billsItem($open));
    $this->assertFalse($named->billsItem($pending));
    $this->assertFalse($named->billsItem($closed));

    // Fields the site did not name mean nothing.
    $this->assertTrue($unnamed->billsItem($pending));
    $this->assertTrue($unnamed->billsItem($closed));

    // A field name the item does not have is not an error.
    $this->assertTrue((new Conventions(['closed_item_field' => 'field_missing']))->billsItem($open));
  }

  /**
   * The owner is sent by uuid or by id, and only when the site names one.
   */
  public function testTheOwnerIsReadFromTheNamedField(): void {
    $owner = User::load(1);
    $this->assertNotNull($owner);
    $order = Order::create(['type' => 'default', 'field_owner' => 1]);

    $this->assertSame($owner->uuid(), $this->container->get('commerce_stripe_recurring.conventions')->ownerOf($order));
    $this->assertSame('1', (new Conventions(['owner_field' => 'field_owner', 'owner_value' => 'id']))->ownerOf($order));
    $this->assertSame('', (new Conventions())->ownerOf($order));
    $this->assertSame('', (new Conventions(['owner_field' => 'field_owner']))->ownerOf(Order::create(['type' => 'default'])));
  }

  /**
   * The site is named in settings.php, and travels under its own key.
   */
  public function testTheSiteComesFromSettings(): void {
    $conventions = Conventions::fromSettings([], new Settings([Conventions::SITE_SETTING => ' shop-a ']));

    $this->assertSame('shop-a', $conventions->site);
    $this->assertSame(['csr_site' => 'shop-a'], $conventions->siteMetadata());
    $this->assertSame(['shop_site' => 'shop-a'], (new Conventions(['site_metadata_key' => 'shop_site'], 'shop-a'))->siteMetadata());
    $this->assertSame([], Conventions::fromSettings([], new Settings([]))->siteMetadata(), 'No site, nothing written.');
  }

  /**
   * What another site made is told apart wherever Stripe carries the name.
   *
   * On a session, a subscription or a payment intent in its own metadata;
   * on an invoice in its parent's, as the subscription had it when the
   * invoice was made.
   */
  public function testAnotherSitesObjectsAreForeign(): void {
    $conventions = new Conventions([], 'shop-a');
    $places = [
      'own' => fn (string $site): array => ['metadata' => ['csr_site' => $site]],
      'invoice' => fn (string $site): array => ['parent' => ['subscription_details' => ['metadata' => ['csr_site' => $site]]]],
      'invoice, before 2025' => fn (string $site): array => ['subscription_details' => ['metadata' => ['csr_site' => $site]]],
    ];

    foreach ($places as $place => $shape) {
      $this->assertTrue($conventions->isForeign(StripeObject::constructFrom($shape('shop-b'))), $place);
      $this->assertFalse($conventions->isForeign(StripeObject::constructFrom($shape('shop-a'))), $place);
    }

    // Made before sites were named: the other checks decide.
    $this->assertFalse($conventions->isForeign(StripeObject::constructFrom(['metadata' => ['order_id' => '42']])));
    $this->assertFalse($conventions->isForeign(StripeObject::constructFrom(['id' => 'cus_1'])));
    // A site that names none wrote no name, so any name is another's.
    $this->assertTrue((new Conventions())->isForeign(StripeObject::constructFrom(['metadata' => ['csr_site' => 'shop-b']])));
  }

}
