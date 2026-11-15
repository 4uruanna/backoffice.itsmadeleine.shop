<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\OrderSnapshot;
use App\Repository\PickupOrderRepository;

/** Même sémantique que le dépôt MySQL (clé unique, reprise, révocation idempotente). */
final class InMemoryPickupOrderRepository implements PickupOrderRepository
{
    /** @var array<int, array<string, mixed>> par shopify_order_id */
    public array $rows = [];
    private int $nextId = 1;

    public function insertIfAbsent(int $shopifyOrderId, ?OrderSnapshot $order, string $status, ?string $errorCode, ?string $lastError, ?string $webhookId): ?int
    {
        if (isset($this->rows[$shopifyOrderId])) {
            return null;
        }

        $this->rows[$shopifyOrderId] = [
            'id' => $this->nextId++,
            'shopify_order_id' => $shopifyOrderId,
            'order_name' => $order?->name ?? ('#' . $shopifyOrderId),
            'customer_email' => $order?->email,
            'status_page_url' => $order?->statusPageUrl,
            'status' => $status,
            'error_code' => $errorCode,
            'last_error' => $lastError,
            'webhook_id' => $webhookId,
            'attempts' => 0,
            'token' => null,
            'revoked_at' => null,
            'revoked_reason' => null,
        ];

        return $this->rows[$shopifyOrderId]['id'];
    }

    public function resumeFromPayloadError(OrderSnapshot $order, string $status, ?string $errorCode, ?string $webhookId): ?int
    {
        $row = $this->rows[$order->shopifyOrderId] ?? null;
        if ($row === null || $row['status'] !== 'error' || $row['error_code'] !== 'payload_errors') {
            return null;
        }

        $this->rows[$order->shopifyOrderId] = [
            'order_name' => $order->name,
            'customer_email' => $order->email,
            'status_page_url' => $order->statusPageUrl,
            'status' => $status,
            'error_code' => $errorCode,
            'last_error' => null,
            'webhook_id' => $webhookId,
            'attempts' => 0,
        ] + $row;

        return $row['id'];
    }

    public function revoke(int $shopifyOrderId, string $reason): bool
    {
        if (!isset($this->rows[$shopifyOrderId]) || $this->rows[$shopifyOrderId]['revoked_at'] !== null) {
            return false;
        }
        $this->rows[$shopifyOrderId]['revoked_at'] = gmdate('Y-m-d H:i:s');
        $this->rows[$shopifyOrderId]['revoked_reason'] = $reason;

        return true;
    }

    public function findByShopifyOrderId(int $shopifyOrderId): ?array
    {
        return $this->rows[$shopifyOrderId] ?? null;
    }

    public function assignToken(int $id, string $token): ?string
    {
        foreach ($this->rows as $shopifyOrderId => $row) {
            if ($row['id'] === $id) {
                $this->rows[$shopifyOrderId]['token'] ??= $token;

                return $this->rows[$shopifyOrderId]['token'];
            }
        }

        return null;
    }

    public function findByToken(string $token): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['token'] === $token) {
                return $row;
            }
        }

        return null;
    }
}
