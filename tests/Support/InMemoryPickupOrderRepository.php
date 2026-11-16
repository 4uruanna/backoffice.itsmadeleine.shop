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
    /** @var (\Closure(): mixed)|null appelé après assignToken (simuler une annulation simultanée) */
    public ?\Closure $afterAssignToken = null;

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
            'pending_reason' => null,
            'locked_at' => null,
            'sent_at' => null,
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
                if ($this->afterAssignToken !== null) {
                    ($this->afterAssignToken)();
                }

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

    public function findById(int $id): ?array
    {
        $key = $this->keyOf($id);

        return $key === null ? null : $this->rows[$key];
    }

    public function claim(int $id): bool
    {
        $key = $this->keyOf($id);
        if ($key === null || !in_array($this->rows[$key]['status'], ['pending', 'failed'], true) || $this->rows[$key]['revoked_at'] !== null) {
            return false;
        }
        $this->rows[$key]['status'] = 'processing';
        $this->rows[$key]['locked_at'] = gmdate('Y-m-d H:i:s');

        return true;
    }

    public function markSent(int $id): void
    {
        $key = $this->keyOf($id);
        if ($key !== null && $this->rows[$key]['status'] === 'processing') {
            $this->rows[$key] = ['status' => 'sent', 'sent_at' => gmdate('Y-m-d H:i:s'), 'locked_at' => null, 'pending_reason' => null, 'last_error' => null] + $this->rows[$key];
        }
    }

    public function markFailed(int $id, string $error, int $maxAttempts): string
    {
        $key = $this->keyOf($id);
        \assert($key !== null);
        $row = $this->rows[$key];
        if ($row['status'] === 'processing') {
            $attempts = $row['attempts'] + 1;
            $this->rows[$key] = [
                'status' => $attempts >= $maxAttempts ? 'error' : 'failed',
                'error_code' => $attempts >= $maxAttempts ? 'max_attempts' : $row['error_code'],
                'attempts' => $attempts,
                'last_error' => mb_substr($error, 0, 500),
                'locked_at' => null,
                'pending_reason' => null,
            ] + $row;
        }

        return $this->rows[$key]['status'];
    }

    public function release(int $id, string $reason): void
    {
        $key = $this->keyOf($id);
        if ($key !== null && $this->rows[$key]['status'] === 'processing') {
            $this->rows[$key] = ['status' => 'pending', 'pending_reason' => $reason, 'locked_at' => null] + $this->rows[$key];
        }
    }

    private function keyOf(int $id): ?int
    {
        foreach ($this->rows as $key => $row) {
            if ($row['id'] === $id) {
                return $key;
            }
        }

        return null;
    }
}
