<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ShopifyHmac;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Sonde (M1-M2) : POST /webhooks/shopify/order-paid.
 * Vérifie le HMAC puis écrit la livraison brute dans var/log/deliveries/.
 * Remplacée par la réception Events en M3.
 */
final class DeliveryProbeController
{
    public function __construct(
        private readonly ?ShopifyHmac $hmac,
        private readonly string $deliveriesDir,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->hmac === null) {
            // Secret absent : erreur de déploiement. 500 pour que Shopify réessaie plus tard.
            error_log('sonde: SHOPIFY_CLIENT_SECRET absent, livraison refusée');

            return $response->withStatus(500);
        }

        $rawBody = (string) $request->getBody();

        if (!$this->hmac->isValidRequest($request, $rawBody)) {
            return $response->withStatus(401);
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (preg_match('/^(x-)?shopify-/i', (string) $name) === 1) {
                $headers[$name] = implode(', ', $values);
            }
        }

        $webhookId = $request->getHeaderLine('Shopify-Webhook-Id')
            ?: $request->getHeaderLine('X-Shopify-Webhook-Id')
            ?: $request->getHeaderLine('Shopify-Event-Id')
            ?: bin2hex(random_bytes(8));
        $safeId = preg_replace('/[^A-Za-z0-9._-]/', '_', $webhookId);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $decoded = json_decode($rawBody, true);

        $record = [
            'received_at' => $now->format(\DateTimeInterface::ATOM),
            'headers' => $headers,
            // Corps brut conservé à l'octet près : permet de recalculer le HMAC (fixtures M2).
            'raw_body' => $rawBody,
            'body' => is_array($decoded) ? $decoded : null,
        ];

        if (!is_dir($this->deliveriesDir) && !mkdir($this->deliveriesDir, 0770, true) && !is_dir($this->deliveriesDir)) {
            error_log('sonde: impossible de créer ' . $this->deliveriesDir);

            return $response->withStatus(500);
        }

        $file = sprintf('%s/%s-%s.json', $this->deliveriesDir, $now->format('Ymd-His'), $safeId);
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($file, (string) $json, LOCK_EX) === false) {
            error_log('sonde: écriture impossible dans ' . $file);

            return $response->withStatus(500);
        }

        return $response->withStatus(200);
    }
}
