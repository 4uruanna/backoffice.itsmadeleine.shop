<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\OrderSnapshot;

/**
 * Écritures de la réception Events. Toutes idempotentes : une livraison rejouée,
 * ou deux livraisons simultanées (create + update), ne créent qu'une ligne.
 */
interface PickupOrderRepository
{
    /**
     * Insère la commande si aucune ligne n'existe pour son identifiant.
     *
     * @return int|null id de la nouvelle ligne, null si une ligne existait déjà
     */
    public function insertIfAbsent(
        int $shopifyOrderId,
        ?OrderSnapshot $order,
        string $status,
        ?string $errorCode,
        ?string $lastError,
        ?string $webhookId,
    ): ?int;

    /**
     * Reprend une ligne en `error` / `payload_errors` avec des données valides.
     *
     * @return int|null id de la ligne reprise, null si aucune ligne dans cet état
     */
    public function resumeFromPayloadError(OrderSnapshot $order, string $status, ?string $errorCode, ?string $webhookId): ?int;

    /** @return bool true si la ligne vient d'être révoquée (false : absente ou déjà révoquée) */
    public function revoke(int $shopifyOrderId, string $reason): bool;

    /** @return array<string, mixed>|null */
    public function findByShopifyOrderId(int $shopifyOrderId): ?array;
}
