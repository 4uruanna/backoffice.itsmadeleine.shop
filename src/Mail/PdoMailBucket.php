<?php

declare(strict_types=1);

namespace App\Mail;

use PDO;

/**
 * Bucket MySQL : verrou nommé, comptage de la dernière heure, insertion. Dates en UTC.
 * Le verrou rend le couple comptage + insertion atomique entre requêtes simultanées
 * (livraisons Shopify, cron).
 */
final readonly class PdoMailBucket implements MailBucket
{
    private const LOCK = 'mail_bucket';

    /** @param \Closure(): PDO $connect */
    public function __construct(
        private \Closure $connect,
        private int $maxPerHour,
        private int $lockTimeoutSeconds = 5,
    ) {
    }

    public function tryAcquire(?int $pickupOrderId, string $kind): bool
    {
        if ($this->maxPerHour <= 0) {
            return false;
        }

        $pdo = ($this->connect)();
        $lock = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $lock->execute([self::LOCK, $this->lockTimeoutSeconds]);
        if ((int) $lock->fetchColumn() !== 1) {
            // Délai dépassé, ou GET_LOCK refusé par la base : traité comme un bucket vide.
            error_log('bucket: verrou mail_bucket indisponible, envoi différé');

            return false;
        }

        try {
            $used = (int) $pdo->query(
                'SELECT COUNT(*) FROM mail_log WHERE sent_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR'
            )->fetchColumn();
            if ($used >= $this->maxPerHour) {
                return false;
            }

            $pdo->prepare('INSERT INTO mail_log (pickup_order_id, kind, sent_at) VALUES (?, ?, UTC_TIMESTAMP())')
                ->execute([$pickupOrderId, $kind]);

            return true;
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK]);
        }
    }
}
