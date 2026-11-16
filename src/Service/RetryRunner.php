<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PickupOrderRepository;

/**
 * Passage du cron de rattrapage (M7), lancé toutes les heures par cron/retry.php :
 * 1. débloque les `processing` abandonnés (crash) ;
 * 2. reprend `pending` et `failed` non révoquées, les plus anciennes d'abord, avec le même
 *    traitement que la réception (PickupProcessor), et s'arrête dès que le bucket est vide ;
 * 3. envoie un seul mail d'alerte listant les nouvelles `error` (reporté si le bucket est vide) ;
 * 4. purge.
 * Le verrou contre deux passages simultanés est pris par cron/retry.php.
 */
final readonly class RetryRunner
{
    public function __construct(
        private PickupOrderRepository $orders,
        private PickupProcessor $processor,
        private AlertMailer $alertMailer,
        private ?Purger $purger,
        private int $maxAttempts = 3,
        private int $staleMinutes = 15,
        private int $batchSize = 500,
        private int $alertLimit = 100,
    ) {
    }

    /** @return list<string> résumé ligne par ligne, sans donnée personnelle (journal du cron OVH) */
    public function run(): array
    {
        $report = [];
        $unlocked = $this->orders->unlockStale($this->staleMinutes, $this->maxAttempts);
        if ($unlocked > 0) {
            $report[] = "débloquées (traitement abandonné depuis plus de {$this->staleMinutes} min) : $unlocked";
        }

        $counts = array_fill_keys(
            [ProcessResult::SENT, ProcessResult::WAITING, ProcessResult::RATE_LIMITED, ProcessResult::FAILED, ProcessResult::ERROR, ProcessResult::SKIPPED],
            0,
        );
        $processed = 0;
        foreach ($this->orders->findRetryable($this->batchSize) as $id) {
            $result = $this->processor->processResult($id);
            $counts[$result->outcome]++;
            $processed++;
            $report[] = '  ' . $result->message;
            if ($result->outcome === ProcessResult::RATE_LIMITED) {
                $report[] = '  plafond d\'envoi atteint : arrêt, la suite au passage suivant';
                break;
            }
        }

        $report[] = sprintf(
            'traitées : %d — envoyées : %d, en attente : %d, plafond : %d, échecs : %d, en erreur : %d, déjà prises : %d',
            $processed,
            $counts[ProcessResult::SENT],
            $counts[ProcessResult::WAITING],
            $counts[ProcessResult::RATE_LIMITED],
            $counts[ProcessResult::FAILED],
            $counts[ProcessResult::ERROR],
            $counts[ProcessResult::SKIPPED],
        );
        $report[] = 'file restante (pending et failed non révoquées) : ' . $this->orders->countRetryable();

        $errors = $this->orders->findUnalertedErrors($this->alertLimit);
        if ($errors === []) {
            $report[] = 'alertes : aucune nouvelle commande en erreur';
        } else {
            [$outcome, $detail] = $this->alertMailer->send($errors);
            if ($outcome === AlertMailer::SENT) {
                $this->orders->markAlerted(array_map(static fn (array $row): int => (int) $row['id'], $errors));
            }
            $report[] = sprintf('alertes : %d nouvelle%s commande%s en erreur, %s', count($errors), count($errors) > 1 ? 's' : '', count($errors) > 1 ? 's' : '', $detail);
        }

        if ($this->purger !== null) {
            $purged = $this->purger->purge();
            $report[] = sprintf(
                'purge : commandes %d, mail_log %d, fichiers de journal %d',
                $purged['orders'],
                $purged['mail_log'],
                $purged['files'],
            );
        }

        return $report;
    }
}
