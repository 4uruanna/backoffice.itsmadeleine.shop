<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Decision;
use App\Domain\OrderEvent;

/**
 * Table de décision de M3 (roadmap/M3-reception-events.md). Code pur, sans base :
 * l'existence d'une ligne est gérée par des requêtes idempotentes dans le dépôt.
 */
final readonly class OrderDecider
{
    /** Statuts traités comme « payée » (PARTIALLY_REFUNDED : première livraison après un remboursement partiel). */
    private const PAID_STATUSES = ['PAID', 'PARTIALLY_REFUNDED'];

    public function __construct(private string $pickupTag)
    {
    }

    public function decide(OrderEvent $event): Decision
    {
        $order = $event->order;

        // errors présent : ne jamais écraser une ligne valide, seulement créer une ligne en erreur si absente.
        if ($event->hasErrors) {
            return $event->shopifyOrderId === null
                ? Decision::nothing('errors sans identifiant de commande')
                : Decision::payloadError((string) $event->errorSummary);
        }

        if ($order === null) {
            return $event->shopifyOrderId === null
                ? Decision::nothing('charge utile sans commande')
                : Decision::payloadError('data.order inutilisable');
        }

        if ($order->isCancelled()) {
            return Decision::revoke('cancelled');
        }

        if ($order->financialStatus === 'REFUNDED') {
            return Decision::revoke('refunded');
        }

        if (!in_array($order->financialStatus, self::PAID_STATUSES, true)) {
            return Decision::nothing('statut ' . ($order->financialStatus ?? 'inconnu'));
        }

        if (!$order->hasTag($this->pickupTag)) {
            return Decision::record(Decision::STATUS_IGNORED, null, 'aucun produit tagué');
        }

        if ($order->email === null) {
            return Decision::record(Decision::STATUS_ERROR, Decision::ERROR_NO_EMAIL, 'commande taguée sans e-mail');
        }

        return Decision::record(Decision::STATUS_PENDING, null, 'commande taguée payée');
    }
}
