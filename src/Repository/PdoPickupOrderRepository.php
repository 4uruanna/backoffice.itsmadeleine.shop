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
                    attempts = 0, last_error = NULL, webhook_id = ?, updated_at = UTC_TIMESTAMP()
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
        // MySQL évalue les affectations de gauche à droite avec les valeurs déjà modifiées :
        // status et error_code sont calculés avant l'incrément d'attempts.
        $pdo->prepare(
            "UPDATE pickup_orders
                SET status = IF(attempts + 1 >= ?, 'error', 'failed'),
                    error_code = IF(attempts + 1 >= ?, 'max_attempts', error_code),
                    attempts = attempts + 1,
                    last_error = ?, locked_at = NULL, pending_reason = NULL, updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status = 'processing'"
        )->execute([$maxAttempts, $maxAttempts, self::cut($error, 500), $id]);

        $statement = $pdo->prepare('SELECT status FROM pickup_orders WHERE id = ?');
        $statement->execute([$id]);

        return (string) $statement->fetchColumn();
    }

    public function release(int $id, string $reason): void
    {
        ($this->connect)()->prepare(
            "UPDATE pickup_orders
                SET status = 'pending', pending_reason = ?, locked_at = NULL, updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status = 'processing'"
        )->execute([self::cut($reason, 32), $id]);
    }

    private static function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
