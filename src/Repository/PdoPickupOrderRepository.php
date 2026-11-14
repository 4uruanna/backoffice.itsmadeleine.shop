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

    private static function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
