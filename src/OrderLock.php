<?php

declare(strict_types=1);

namespace Drupal\commerce_stripe_recurring;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * The one lock every write to a subscription order takes.
 *
 * Named `{lock_prefix}{id}` (Conventions): a site whose own code locks
 * orders uses the same prefix, so a webhook, the checkout's session record
 * and a plan change made by the site serialize on the same order. The order
 * is reloaded under the lock: a copy loaded before waiting is stale, and
 * saving it would erase what the holder before wrote.
 */
final readonly class OrderLock {

  public function __construct(
    private LockBackendInterface $lock,
    private EntityTypeManagerInterface $entityTypeManager,
    private Conventions $conventions,
  ) {}

  /**
   * The lock name of an order.
   */
  public function nameOf(OrderInterface $order): string {
    return $this->conventions->lockPrefix . $order->id();
  }

  /**
   * Runs a callback with a fresh copy of the order, under its lock.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order; only its id is used.
   * @param callable(\Drupal\commerce_order\Entity\OrderInterface): mixed $callback
   *   Receives the order as stored now. It saves it itself when it changes it.
   * @param float $wait
   *   Seconds to wait for the lock.
   *
   * @return mixed
   *   What the callback returns.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the lock stays held.
   */
  public function run(OrderInterface $order, callable $callback, float $wait = 15.0): mixed {
    $id = $order->id();
    if ($id === NULL) {
      return $callback($order);
    }
    $name = $this->nameOf($order);
    if (!$this->lock->acquire($name, 30.0) && (!$this->lock->wait($name, (int) ceil($wait)) || !$this->lock->acquire($name, 30.0))) {
      throw new PaymentGatewayException('The order is being changed elsewhere. Try again in a moment.');
    }
    try {
      $storage = $this->entityTypeManager->getStorage('commerce_order');
      $storage->resetCache([$id]);
      $fresh = $storage->load($id);
      return $callback($fresh instanceof OrderInterface ? $fresh : $order);
    }
    finally {
      $this->lock->release($name);
    }
  }

  /**
   * Changes an order's `data` under its lock and saves it.
   *
   * The caller's copy gets the stored `data` back, so it is not stale.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param callable(\Drupal\commerce_order\Entity\OrderInterface): void $mutate
   *   Changes the fresh copy.
   */
  public function update(OrderInterface $order, callable $mutate): void {
    $this->run($order, static function (OrderInterface $fresh) use ($mutate, $order): void {
      $mutate($fresh);
      $fresh->save();
      if ($fresh !== $order) {
        $order->set('data', $fresh->get('data')->getValue());
      }
    });
  }

}
