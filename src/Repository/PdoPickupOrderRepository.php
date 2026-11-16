<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\OrderSnapshot;
use PDO;

final class PdoPickupOrderRepository implements PickupOrderRepository
{
    /** @param \Closure(): PDO $connect */
    public function __construct(private readonly \Closure $connect)
    {
    }

    public function insertIfAbsent(
        int $shopifyOrderId,
        ?OrderSnapshot $order,
        string $status,
        ?string $errorCode,
        ?string $lastError,
        ?string $webhookId,
    ): ?int {
        $pdo = ($this->connect)();
        // Clé unique shopify_order_id : atomique face à deux livraisons simultanées.
        // « ON DUPLICATE KEY UPDATE id = id » plutôt qu'INSERT IGNORE : seul le doublon est
        // ignoré (0 ligne touchée), toute autre erreur reste une exception.
        $statement = $pdo->prepare(
            'INSERT INTO pickup_orders
                (shopify_order_id, order_name, customer_email, status_page_url, status, error_code,
                 last_error, webhook_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE id = id'
        );
        $statement->execute([
            $shopifyOrderId,
            self::cut($order?->name ?? ('#' . $shopifyOrderId), 32),
            self::cut($order?->email, 255),
            self::cut($order?->statusPageUrl, 500),
            $status,
            $errorCode,
            self::cut($lastError, 500),
            self::cut($webhookId, 64),
        ]);

        return $statement->rowCount() === 1 ? (int) $pdo->lastInsertId() : null;
    }

    public function resumeFromPayloadError(OrderSnapshot $order, string $status, ?string $errorCode, ?string $webhookId): ?int
    {
        $pdo = ($this->connect)();
        $statement = $pdo->prepare(
            "UPDATE pickup_orders
                SET order_name = ?, customer_email = ?, status_page_url = ?, status = ?, error_code = ?,
                    attempts = 0, last_error = NULL, alerted_at = NULL, webhook_id = ?, updated_at = UTC_TIMESTAMP()
              WHERE shopify_order_id = ? AND status = 'error' AND error_code = 'payload_errors'"
        );
        $statement->execute([
            self::cut($order->name, 32),
            self::cut($order->email, 255),
            self::cut($order->statusPageUrl, 500),
            $status,
            $errorCode,
            self::cut($webhookId, 64),
            $order->shopifyOrderId,
        ]);

        if ($statement->rowCount() !== 1) {
            return null;
        }

        $id = $pdo->prepare('SELECT id FROM pickup_orders WHERE shopify_order_id = ?');
        $id->execute([$order->shopifyOrderId]);

        return (int) $id->fetchColumn();
    }

    public function revoke(int $shopifyOrderId, string $reason): bool
    {
        $statement = ($this->connect)()->prepare(
            'UPDATE pickup_orders
                SET revoked_at = UTC_TIMESTAMP(), revoked_reason = ?, updated_at = UTC_TIMESTAMP()
              WHERE shopify_order_id = ? AND revoked_at IS NULL'
        );
        $statement->execute([$reason, $shopifyOrderId]);

        return $statement->rowCount() === 1;
    }

    public function findByShopifyOrderId(int $shopifyOrderId): ?array
    {
        $statement = ($this->connect)()->prepare('SELECT * FROM pickup_orders WHERE shopify_order_id = ?');
        $statement->execute([$shopifyOrderId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function assignToken(int $id, string $token): ?string
    {
        $pdo = ($this->connect)();
        // « token IS NULL » : deux traitements simultanés ne peuvent pas écraser un jeton déjà envoyé.
        $pdo->prepare('UPDATE pickup_orders SET token = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND token IS NULL')
            ->execute([$token, $id]);

        $statement = $pdo->prepare('SELECT token FROM pickup_orders WHERE id = ?');
        $statement->execute([$id]);
        $current = $statement->fetchColumn();

        return $current === false ? null : (string) $current;
    }

    public function findByToken(string $token): ?array
    {
        $statement = ($this->connect)()->prepare('SELECT * FROM pickup_orders WHERE token = ?');
        $statement->execute([$token]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $statement = ($this->connect)()->prepare('SELECT * FROM pickup_orders WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function claim(int $id): bool
    {
        $statement = ($this->connect)()->prepare(
            "UPDATE pickup_orders
                SET status = 'processing', locked_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status IN ('pending', 'failed') AND revoked_at IS NULL"
        );
        $statement->execute([$id]);

        return $statement->rowCount() === 1;
    }

    public function markSent(int $id): void
    {
        ($this->connect)()->prepare(
            "UPDATE pickup_orders
                SET status = 'sent', sent_at = UTC_TIMESTAMP(), locked_at = NULL, pending_reason = NULL,
                    last_error = NULL, updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status = 'processing'"
        )->execute([$id]);
    }

    public function markFailed(int $id, string $error, int $maxAttempts): string
    {
        $pdo = ($this->connect)();
        $pdo->prepare(
            "UPDATE pickup_orders
                SET {$this->failedAssignments()},
                    last_error = ?, locked_at = NULL, pending_reason = NULL, updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status = 'processing'"
        )->execute([$maxAttempts, $maxAttempts, $maxAttempts, self::cut($error, 500), $id]);

        $statement = $pdo->prepare('SELECT status FROM pickup_orders WHERE id = ?');
        $statement->execute([$id]);

        return (string) $statement->fetchColumn();
    }

    public function release(int $id, string $reason, ?string $lastError = null): void
    {
        ($this->connect)()->prepare(
            "UPDATE pickup_orders
                SET status = 'pending', pending_reason = ?, last_error = COALESCE(?, last_error),
                    locked_at = NULL, updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status = 'processing'"
        )->execute([self::cut($reason, 32), self::cut($lastError, 500), $id]);
    }

    public function findRetryable(int $limit): array
    {
        $statement = ($this->connect)()->prepare(
            "SELECT id FROM pickup_orders
              WHERE status IN ('pending', 'failed') AND revoked_at IS NULL
              ORDER BY created_at, id
              LIMIT ?"
        );
        $statement->execute([$limit]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function countRetryable(): int
    {
        return (int) ($this->connect)()->query(
            "SELECT COUNT(*) FROM pickup_orders WHERE status IN ('pending', 'failed') AND revoked_at IS NULL"
        )->fetchColumn();
    }

    public function unlockStale(int $minutes, int $maxAttempts): int
    {
        $statement = ($this->connect)()->prepare(
            "UPDATE pickup_orders
                SET {$this->failedAssignments()},
                    last_error = 'stale_lock', locked_at = NULL, pending_reason = NULL, updated_at = UTC_TIMESTAMP()
              WHERE status = 'processing'
                AND (locked_at IS NULL OR locked_at < UTC_TIMESTAMP() - INTERVAL ? MINUTE)"
        );
        $statement->execute([$maxAttempts, $maxAttempts, $maxAttempts, $minutes]);

        return $statement->rowCount();
    }

    public function findUnalertedErrors(int $limit): array
    {
        $statement = ($this->connect)()->prepare(
            "SELECT id, shopify_order_id, order_name, error_code, attempts, last_error, created_at, updated_at
               FROM pickup_orders
              WHERE status = 'error' AND alerted_at IS NULL
              ORDER BY created_at, id
              LIMIT ?"
        );
        $statement->execute([$limit]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markAlerted(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        ($this->connect)()->prepare(
            "UPDATE pickup_orders SET alerted_at = UTC_TIMESTAMP() WHERE id IN ($placeholders)"
        )->execute(array_values($ids));
    }

    /**
     * Affectations d'un échec compté (3 paramètres : $maxAttempts × 3). MySQL évalue les
     * affectations de gauche à droite avec les valeurs déjà modifiées : status, error_code et
     * alerted_at sont calculés avant l'incrément d'attempts. Une ligne qui passe en `error`
     * redevient à signaler (alerted_at = NULL).
     */
    private function failedAssignments(): string
    {
        return "status = IF(attempts + 1 >= ?, 'error', 'failed'),
                    error_code = IF(attempts + 1 >= ?, 'max_attempts', error_code),
                    alerted_at = IF(attempts + 1 >= ?, NULL, alerted_at),
                    attempts = attempts + 1";
    }

    private static function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
