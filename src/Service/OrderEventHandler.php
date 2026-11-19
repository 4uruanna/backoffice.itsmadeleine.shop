<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Decision;
use App\Domain\OrderEvent;
use App\Repository\PickupOrderRepository;

/**
 * Applique la décision de M3 à la base, programme le traitement des commandes
 * à envoyer et signale les erreurs.
 */
final class OrderEventHandler
{
    /**
     * @param \Closure(int): void $process traitement d'une ligne `pending` (M4-M7), différé après la réponse
     */
    public function __construct(
        private readonly OrderDecider $decider,
        private readonly PickupOrderRepository $orders,
        private readonly AlertLog $alerts,
        private readonly DeferredTasks $tasks,
        private readonly \Closure $process,
    ) {
    }

    /** @return string résumé pour le journal (aucune donnée personnelle) */
    public function handle(OrderEvent $event, ?string $webhookId): string
    {
        $decision = $this->decider->decide($event);
        $id = $event->shopifyOrderId;

        switch ($decision->action) {
            case Decision::RECORD_PAYLOAD_ERROR:
                \assert($id !== null);
                $code = (string) $decision->errorCode;
                $rowId = $this->orders->insertIfAbsent(
                    $id, $event->order, Decision::STATUS_ERROR, $code, $decision->reason, $webhookId,
                );
                if ($rowId === null) {
                    return "commande $id : $code ignoré, ligne existante conservée";
                }
                $this->alerts->alert(
                    $code,
                    $code === Decision::ERROR_ORDER_NOT_FOUND
                        ? 'Commande introuvable dans Shopify (supprimée ?), rien à envoyer'
                        : 'Livraison avec errors, commande non traitée',
                    ['shopify_order_id' => $id, 'errors' => $decision->reason],
                );

                return "commande $id : error/$code";

            case Decision::REVOKE:
                \assert($id !== null);
                $revoked = $this->orders->revoke($id, (string) $decision->revokedReason);

                return "commande $id : " . ($revoked ? 'révoquée (' . $decision->revokedReason . ')' : 'rien à révoquer');

            case Decision::RECORD:
                $order = $event->order;
                \assert($order !== null && $decision->status !== null);
                $rowId = $this->orders->resumeFromPayloadError($order, $decision->status, $decision->errorCode, $webhookId);
                $verb = 'reprise';
                if ($rowId === null) {
                    $rowId = $this->orders->insertIfAbsent(
                        $order->shopifyOrderId, $order, $decision->status, $decision->errorCode, null, $webhookId,
                    );
                    $verb = 'créée';
                }
                if ($rowId === null) {
                    return "commande {$order->shopifyOrderId} : doublon, rien à faire";
                }

                if ($decision->status === Decision::STATUS_PENDING) {
                    $process = $this->process;
                    $this->tasks->add("traiter la commande {$order->shopifyOrderId}", static fn () => $process($rowId));
                } elseif ($decision->errorCode === Decision::ERROR_NO_EMAIL) {
                    $this->alerts->alert(Decision::ERROR_NO_EMAIL, 'Commande taguée sans e-mail client, QR non envoyé', [
                        'shopify_order_id' => $order->shopifyOrderId,
                        'order_name' => $order->name,
                    ]);
                }

                return "commande {$order->shopifyOrderId} : $verb, {$decision->status}"
                    . ($decision->errorCode !== null ? "/{$decision->errorCode}" : '');

            default:
                return 'commande ' . ($id ?? '?') . ' : rien (' . $decision->reason . ')';
        }
    }
}
