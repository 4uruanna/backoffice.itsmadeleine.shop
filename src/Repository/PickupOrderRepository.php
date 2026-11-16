<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\OrderSnapshot;

/**
 * Commandes à retrait par QR. Écritures de la réception Events toutes idempotentes :
 * une livraison rejouée, ou deux livraisons simultanées (create + update), ne créent qu'une ligne.
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

    /**
     * Attribue un jeton à la ligne si elle n'en a pas encore (M4). Un nouvel essai
     * réutilise ainsi le même QR.
     *
     * @return string|null jeton de la ligne (nouveau ou existant), null si la ligne n'existe pas
     */
    public function assignToken(int $id, string $token): ?string;

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array;

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /**
     * Prise en charge atomique : `pending` ou `failed`, non révoquée → `processing`.
     *
     * @return bool true si cet appel a pris la ligne (sinon : déjà prise, envoyée, révoquée…)
     */
    public function claim(int $id): bool;

    /** `processing` → `sent`, `sent_at`. */
    public function markSent(int $id): void;

    /**
     * Échec d'envoi : `attempts + 1`, `last_error` ; `failed`, ou `error` / `max_attempts`
     * quand `attempts` atteint $maxAttempts.
     *
     * @return string nouveau statut (`failed` ou `error`)
     */
    public function markFailed(int $id, string $error, int $maxAttempts): string;

    /** Rend la ligne sans tentative d'envoi : `processing` → `pending`, avec la raison (`attempts` inchangé). */
    public function release(int $id, string $reason): void;
}
