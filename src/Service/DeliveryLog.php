<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Copie brute des livraisons (ancienne sonde de M1-M2), activée par DEBUG_DELIVERIES=1.
 * Contient des données personnelles : à désactiver après la recette, purge en M7.
 */
final readonly class DeliveryLog
{
    public function __construct(private string $dir)
    {
    }

    public function write(ServerRequestInterface $request, string $rawBody): void
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (preg_match('/^(x-)?shopify-/i', (string) $name) === 1) {
                $headers[$name] = implode(', ', $values);
            }
        }

        $webhookId = self::webhookId($request) ?? bin2hex(random_bytes(8));
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

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Dossier des livraisons impossible à créer : ' . $this->dir);
        }

        $file = sprintf('%s/%s-%s.json', $this->dir, $now->format('Ymd-His'), $safeId);
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($file, (string) $json, LOCK_EX) === false) {
            throw new \RuntimeException('Écriture impossible : ' . $file);
        }
    }

    public static function webhookId(ServerRequestInterface $request): ?string
    {
        foreach (['Shopify-Webhook-Id', 'X-Shopify-Webhook-Id', 'Shopify-Event-Id'] as $header) {
            $value = $request->getHeaderLine($header);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
