<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\ScanRepository;

final class InMemoryScanRepository implements ScanRepository
{
    /** @var list<array{pickup_order_id: int, result: string, ip: ?string, user_agent: ?string}> */
    public array $scans = [];
    public bool $fail = false;

    public function record(int $pickupOrderId, string $result, ?string $ip, ?string $userAgent): void
    {
        if ($this->fail) {
            throw new \PDOException('base indisponible');
        }

        $this->scans[] = [
            'pickup_order_id' => $pickupOrderId,
            'result' => $result,
            'ip' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ];
    }
}
