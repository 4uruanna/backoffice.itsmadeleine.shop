<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Mail\MailBucket;

/** Bucket en mémoire : `$remaining` jetons, chaque prise journalisée. */
final class FakeMailBucket implements MailBucket
{
    /** @var list<array{pickup_order_id: ?int, kind: string}> */
    public array $taken = [];

    public function __construct(public int $remaining = PHP_INT_MAX)
    {
    }

    public function tryAcquire(?int $pickupOrderId, string $kind): bool
    {
        if ($this->remaining <= 0) {
            return false;
        }
        $this->remaining--;
        $this->taken[] = ['pickup_order_id' => $pickupOrderId, 'kind' => $kind];

        return true;
    }
}
