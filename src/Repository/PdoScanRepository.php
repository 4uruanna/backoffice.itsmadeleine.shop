<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final readonly class PdoScanRepository implements ScanRepository
{
    /** @param \Closure(): PDO $connect */
    public function __construct(private \Closure $connect)
    {
    }

    public function record(int $pickupOrderId, string $result, ?string $ip, ?string $userAgent): void
    {
        ($this->connect)()->prepare(
            'INSERT INTO scans (pickup_order_id, result, scanned_at, ip, user_agent)
             VALUES (?, ?, UTC_TIMESTAMP(), ?, ?)'
        )->execute([
            $pickupOrderId,
            $result,
            $ip === null ? null : mb_substr($ip, 0, 45),
            $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ]);
    }
}
