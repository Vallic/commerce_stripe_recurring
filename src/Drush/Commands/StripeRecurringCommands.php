<?php

declare(strict_types=1);

namespace Drupal\commerce_stripe_recurring\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_stripe_recurring\Conventions;
use Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Names this site on the subscriptions it made before sites were named.
 */
final class StripeRecurringCommands extends DrushCommands {

  /**
   * Orders loaded at once.
   */
  private const int BATCH = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Conventions $conventions,
  ) {
    parent::__construct();
  }

  /**
   * Creates the commands.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('commerce_stripe_recurring.conventions'),
    );
  }

  /**
   * Names this site on every subscription its orders record.
   *
   * For a Stripe account shared with other sites: the invoices Stripe makes
   * from a named subscription carry the name, and the other sites drop their
   * events. A name already there is never changed.
   *
   * Run it where the orders were made, never on a copy of that database: a
   * copy holds the same subscription ids, and would name itself on the
   * original site's subscriptions.
   */
  #[CLI\Command(name: 'commerce-stripe-recurring:stamp-site', aliases: ['csr-stamp-site'])]
  #[CLI\Usage(name: 'drush commerce-stripe-recurring:stamp-site', description: "Name this site on its existing subscriptions, once its settings.php names it.")]
  public function stampSite(): int {
    if ($this->conventions->site === '') {
      $this->io()->error(dt("No site is named. Set \$settings['@setting'] in settings.php first.", ['@setting' => Conventions::SITE_SETTING]));
      return Command::FAILURE;
    }

    $gateways = [];
    foreach ($this->entityTypeManager->getStorage('commerce_payment_gateway')->loadMultiple() as $gateway) {
      $plugin = $gateway instanceof PaymentGatewayInterface ? $gateway->getPlugin() : NULL;
      if ($plugin instanceof StripeSubscription) {
        $gateways[(string) $gateway->id()] = $plugin;
      }
    }
    if ($gateways === []) {
      $this->io()->note(dt('No Stripe subscription gateway, so nothing to name.'));
      return Command::SUCCESS;
    }

    if (!$this->io()->confirm(dt('Name the site "@site" on the Stripe subscriptions of this database\'s orders? Only on the site where they were made, never on a copy.', ['@site' => $this->conventions->site]), FALSE)) {
      return Command::FAILURE;
    }

    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('payment_gateway', array_keys($gateways), 'IN')
      ->sort('order_id')
      ->execute();

    $named = 0;
    $failed = 0;
    foreach (array_chunk($ids, self::BATCH) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $order) {
        if (!$order instanceof OrderInterface) {
          continue;
        }
        $gateway = $gateways[(string) $order->get('payment_gateway')->target_id] ?? NULL;
        try {
          $named += (int) $gateway?->stampSite($order);
        }
        catch (PaymentGatewayException $e) {
          $failed++;
          $this->logger()?->warning($e->getMessage());
        }
      }
      $storage->resetCache($chunk);
    }

    $this->io()->success(dt('Named the site on @named subscription(s), of @orders order(s).', [
      '@named' => $named,
      '@orders' => count($ids),
    ]));

    return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
  }

}
