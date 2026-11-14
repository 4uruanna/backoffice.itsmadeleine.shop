<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\AlertLog;

final class SpyAlertLog extends AlertLog
{
    /** @var list<array{code: string, message: string, context: array<string, mixed>}> */
    public array $alerts = [];

    public function __construct()
    {
        parent::__construct('/dev/null');
    }

    public function alert(string $code, string $message, array $context = []): void
    {
        $this->alerts[] = ['code' => $code, 'message' => $message, 'context' => $context];
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_column($this->alerts, 'code');
    }
}
