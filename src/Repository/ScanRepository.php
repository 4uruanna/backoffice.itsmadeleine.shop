<?php

declare(strict_types=1);

namespace App\Repository;

/** Journal des scans de QR (table `scans`, M4). */
interface ScanRepository
{
    public const REDIRECTED = 'redirected';
    public const REVOKED = 'revoked';

    /** @param self::REDIRECTED|self::REVOKED $result */
    public function record(int $pickupOrderId, string $result, ?string $ip, ?string $userAgent): void;
}
