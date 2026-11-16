<?php

declare(strict_types=1);

namespace App;

use App\Mail\MailBucket;
use App\Mail\MailTransport;
use App\Mail\MailTransports;
use App\Mail\PdoMailBucket;
use App\Mail\QrMailBuilder;
use App\Repository\PdoPickupOrderRepository;
use App\Repository\PickupOrderRepository;
use App\Service\AlertLog;
use App\Service\AlertMailer;
use App\Service\PickupProcessor;
use App\Service\Purger;
use App\Service\QrGenerator;
use App\Service\RetryRunner;
use App\Service\Template;
use PDO;

/**
 * Construction partagée par l'API (AppFactory) et le cron (cron/retry.php) :
 * même traitement des commandes, même transport, même bucket.
 */
final class Services
{
    /** @return \Closure(): PDO connexion ouverte au premier appel, puis réutilisée */
    public static function connection(Config $config): \Closure
    {
        $pdo = null;

        return static function () use ($config, &$pdo): PDO {
            return $pdo ??= Database::connect($config);
        };
    }

    public static function pickupProcessor(
        Config $config,
        PickupOrderRepository $orders,
        Template $templates,
        MailTransport $transport,
        MailBucket $bucket,
        AlertLog $alerts,
    ): PickupProcessor {
        return new PickupProcessor(
            $orders,
            new QrMailBuilder(
                $templates,
                new QrGenerator((string) $config->get('APP_URL', 'https://app.itsmadeleine.shop')),
                (string) $config->get('MAIL_CONTACT', QrMailBuilder::DEFAULT_CONTACT),
            ),
            $transport,
            $bucket,
            $alerts,
            $config->int('MAX_ATTEMPTS', 3),
            self::allowlist($config->get('MAIL_ALLOWLIST')),
        );
    }

    /** Passage du cron de rattrapage (M7), avec les services de production. */
    public static function retryRunner(Config $config, string $projectRoot, \Closure $connect): RetryRunner
    {
        $orders = new PdoPickupOrderRepository($connect);
        $transport = MailTransports::fromConfig($config);
        $bucket = new PdoMailBucket($connect, $config->int('MAIL_MAX_PER_HOUR', 120));

        return new RetryRunner(
            $orders,
            self::pickupProcessor(
                $config,
                $orders,
                new Template($projectRoot . '/templates'),
                $transport,
                $bucket,
                new AlertLog($projectRoot . '/var/log/alerts.log'),
            ),
            new AlertMailer($transport, $bucket, $config->get('ALERT_EMAIL'), $config->get('SHOPIFY_STORE_HANDLE')),
            new Purger(
                $connect,
                $projectRoot . '/var/log',
                $config->int('RETENTION_MONTHS', 12),
                $config->int('MAIL_LOG_RETENTION_DAYS', 30),
            ),
            $config->int('MAX_ATTEMPTS', 3),
        );
    }

    /** @return list<string> adresses en minuscules ; vide = aucun filtre */
    public static function allowlist(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $address): string => strtolower(trim($address)),
            explode(',', $value),
        )));
    }
}
