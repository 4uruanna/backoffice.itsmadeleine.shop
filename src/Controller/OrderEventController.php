<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\OrderEvent;
use App\Service\DeliveryLog;
use App\Service\OrderEventHandler;
use App\Service\ShopifyHmac;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /webhooks/shopify/order-paid : réception des Events `Order` (M3).
 * Signature invalide → 401, sans aucune écriture. Signature valide → 200, même quand
 * rien n'est fait (sinon Shopify relivre). Erreur interne (base…) → 500 : Shopify réessaiera.
 */
final readonly class OrderEventController
{
    /**
     * @param DeliveryLog|null $deliveryLog copie de toutes les livraisons (DEBUG_DELIVERIES=1)
     * @param DeliveryLog|null $anomalyLog  copie des seules livraisons sans commande exploitable,
     *                                      toujours active : sans elle, impossible de savoir après coup
     *                                      ce que Shopify a envoyé (purge du cron après 30 jours)
     */
    public function __construct(
        private ?ShopifyHmac $hmac,
        private OrderEventHandler $handler,
        private ?DeliveryLog $deliveryLog,
        private ?DeliveryLog $anomalyLog = null,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->hmac === null) {
            error_log('events: SHOPIFY_CLIENT_SECRET absent, livraison refusée');

            return $response->withStatus(500);
        }

        $rawBody = (string) $request->getBody();
        if (!$this->hmac->isValidRequest($request, $rawBody)) {
            return $response->withStatus(401);
        }

        $webhookId = DeliveryLog::webhookId($request);

        if ($this->deliveryLog !== null) {
            try {
                $this->deliveryLog->write($request, $rawBody);
            } catch (\Throwable $e) {
                error_log('events: copie de la livraison impossible : ' . $e->getMessage());
            }
        }

        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            error_log('events: corps JSON illisible, livraison ' . ($webhookId ?? '?'));

            return $response->withStatus(200);
        }

        $event = OrderEvent::fromPayload($body);
        if ($event->order === null && $this->deliveryLog === null && $this->anomalyLog !== null) {
            try {
                $this->anomalyLog->write($request, $rawBody);
            } catch (\Throwable $e) {
                error_log('events: copie de la livraison anormale impossible : ' . $e->getMessage());
            }
        }

        $summary = $this->handler->handle($event, $webhookId);
        error_log(sprintf('events: %s (livraison %s)', $summary, $webhookId ?? '?'));

        return $response->withStatus(200);
    }
}
