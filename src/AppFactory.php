<?php

declare(strict_types=1);

namespace App;

use App\Controller\HealthController;
use App\Controller\OrderEventController;
use App\Controller\ScanController;
use App\Mail\MailBucket;
use App\Mail\MailTransport;
use App\Mail\PdoMailBucket;
use App\Mail\QrMailBuilder;
use App\Mail\SmtpTransport;
use App\Repository\PdoPickupOrderRepository;
use App\Repository\PdoScanRepository;
use App\Repository\PickupOrderRepository;
use App\Repository\ScanRepository;
use App\Service\AlertLog;
use App\Service\DeferredTasks;
use App\Service\DeliveryLog;
use App\Service\OrderDecider;
use App\Service\OrderEventHandler;
use App\Service\PickupProcessor;
use App\Service\QrGenerator;
use App\Service\ShopifyHmac;
use App\Service\Template;
use PDO;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

/**
 * Construit l'application Slim et ses routes.
 */
final class AppFactory
{
    /**
     * @param (\Closure(): PDO)|null $connect connexion à la base (remplaçable dans les tests)
     * @param DeferredTasks|null $tasks tâches à exécuter après la réponse (voir public/index.php)
     */
    public static function create(
        Config $config,
        string $projectRoot,
        ?\Closure $connect = null,
        ?DeferredTasks $tasks = null,
        ?PickupOrderRepository $orders = null,
        ?AlertLog $alerts = null,
        ?ScanRepository $scans = null,
        ?Template $templates = null,
        ?MailTransport $transport = null,
        ?MailBucket $bucket = null,
    ): App {
        $connect ??= self::lazyConnection($config);
        $tasks ??= new DeferredTasks();
        $orders ??= new PdoPickupOrderRepository($connect);
        $alerts ??= new AlertLog($projectRoot . '/var/log/alerts.log');
        $scans ??= new PdoScanRepository($connect);
        $templates ??= new Template($projectRoot . '/templates');
        $transport ??= SmtpTransport::fromConfig($config);
        $bucket ??= new PdoMailBucket($connect, $config->int('MAIL_MAX_PER_HOUR', 120));

        $processor = new PickupProcessor(
            $orders,
            new QrMailBuilder($templates, new QrGenerator((string) $config->get('APP_URL', 'https://app.itsmadeleine.shop'))),
            $transport,
            $bucket,
            $alerts,
            $config->int('MAX_ATTEMPTS', 3),
            self::allowlist($config->get('MAIL_ALLOWLIST')),
        );

        $secret = $config->get('SHOPIFY_CLIENT_SECRET');
        $hmac = $secret === null ? null : new ShopifyHmac($secret);

        $deliveryLog = $config->get('DEBUG_DELIVERIES') === '1'
            ? new DeliveryLog($projectRoot . '/var/log/deliveries')
            : null;

        $handler = new OrderEventHandler(
            new OrderDecider($config->get('PICKUP_TAG', 'retrait-qr')),
            $orders,
            $alerts,
            $tasks,
            // Traitement d'une commande `pending` : prise en charge, jeton, bucket (M6), mail (M5).
            static function (int $pickupOrderId) use ($processor): void {
                error_log('events: ' . $processor->process($pickupOrderId));
            },
        );

        $app = SlimAppFactory::create();
        $app->addRoutingMiddleware();

        // Erreurs détaillées à l'écran hors production ; journal sans trace complète.
        $isProduction = $config->get('APP_ENV', 'production') === 'production';
        $app->addErrorMiddleware(!$isProduction, true, false);

        $app->get('/health', new HealthController($connect));
        $app->post('/webhooks/shopify/order-paid', new OrderEventController($hmac, $handler, $deliveryLog));
        $app->get('/r/{token}', new ScanController(
            $orders,
            $scans,
            $templates,
            $config->get('SHOPIFY_STORE_HANDLE'),
        ));

        return $app;
    }

    /** @return list<string> adresses en minuscules ; vide = aucun filtre */
    private static function allowlist(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $address): string => strtolower(trim($address)),
            explode(',', $value),
        )));
    }

    /** @return \Closure(): PDO */
    private static function lazyConnection(Config $config): \Closure
    {
        $pdo = null;

        return static function () use ($config, &$pdo): PDO {
            return $pdo ??= Database::connect($config);
        };
    }
}
