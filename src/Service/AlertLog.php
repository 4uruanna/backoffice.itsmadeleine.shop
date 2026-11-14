<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Alertes internes. M3 : une ligne JSON dans var/log/alerts.log.
 * M7 : envoi en plus d'un mail à ALERT_EMAIL.
 */
class AlertLog
{
    public function __construct(private readonly string $file)
    {
    }

    /** @param array<string, scalar|null> $context */
    public function alert(string $code, string $message, array $context = []): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $line = json_encode([
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'code' => $code,
            'message' => $message,
        ] + $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log('alerte non écrite : ' . $line);
        }
    }
}
