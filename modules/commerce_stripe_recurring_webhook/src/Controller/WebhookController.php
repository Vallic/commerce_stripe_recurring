<?php

declare(strict_types=1);

namespace Drupal\commerce_stripe_recurring_webhook\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_stripe_recurring\OrderEvents;
use Drupal\commerce_stripe_recurring\Plugin\Commerce\PaymentGateway\StripeSubscription;
use Drupal\commerce_stripe_recurring\StripeCredentialsInterface;
use Drupal\commerce_stripe_recurring_webhook\Event\WebhookEvent;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives what Stripe says about a subscription after the customer has gone.
 *
 * The signature is checked before anything is read. The order follows the
 * invoice, not the checkout (OrderEvents), and every verified event is then
 * dispatched as a WebhookEvent for the site's own handlers.
 */
final class WebhookController implements ContainerInjectionInterface {

  public function __construct(
    private readonly OrderEvents $orderEvents,
    private readonly StripeCredentialsInterface $credentials,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('commerce_stripe_recurring.order_events'),
      $container->get('commerce_stripe_recurring.credentials'),
      $container->get('event_dispatcher'),
      $container->get('logger.channel.commerce_stripe_recurring'),
    );
  }

  /**
   * Receives one event.
   *
   * An unsigned POST to a public URL that could mark orders paid is not a
   * webhook, it is an invitation: nothing is read before the signature is
   * verified.
   */
  public function receive(Request $request, PaymentGatewayInterface $commerce_payment_gateway): JsonResponse {
    $plugin = $commerce_payment_gateway->getPlugin();

    if (!$plugin instanceof StripeSubscription) {
      return new JsonResponse(['error' => 'Not a Stripe subscription gateway.'], 400);
    }

    // Empty also when the gateway holds something that is not a signing
    // secret: secrets are usually kept out of exported config, so what the
    // gateway holds may be a placeholder such as `N/A`. Used as a key it
    // would fail every event with "bad signature" and send somebody to hunt
    // for a mismatch in the Stripe dashboard, when the answer is that this
    // environment was never given the secret.
    $secret = $this->credentials->webhookSecret($plugin->getConfiguration());

    if ($secret === '') {
      $this->logger->error('A Stripe event arrived for gateway @id, which has no usable signing secret. Refused.', [
        '@id' => $commerce_payment_gateway->id(),
      ]);

      return new JsonResponse(['error' => 'No signing secret configured.'], 400);
    }

    try {
      $event = Webhook::constructEvent(
        $request->getContent(),
        (string) $request->headers->get('stripe-signature', ''),
        $secret,
      );
    }
    catch (SignatureVerificationException | \UnexpectedValueException $e) {
      $this->logger->warning('A Stripe event failed signature checking: @message', ['@message' => $e->getMessage()]);

      return new JsonResponse(['error' => 'Bad signature.'], 400);
    }

    $object = $event->data->object ?? NULL;
    $handled = is_object($object)
      && $this->orderEvents->handle((string) $event->type, $object, isset($event->created) ? (int) $event->created : NULL);

    $dispatched = new WebhookEvent($commerce_payment_gateway, $event, $handled);
    $this->eventDispatcher->dispatch($dispatched, WebhookEvent::NAME);

    // 200 either way. Stripe retries a non-2xx, and retrying an event this
    // deliberately ignores would be a queue that never drains.
    return new JsonResponse(['handled' => $dispatched->isHandled()]);
  }

}
