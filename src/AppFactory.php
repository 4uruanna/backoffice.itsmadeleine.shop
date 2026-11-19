<?php

declare(strict_types=1);

namespace App;

use App\Controller\HealthController;
use App\Controller\OrderEventController;
use App\Controller\ScanController;
use App\Mail\MailBucket;
use App\Mail\MailTransport;
use App\Mail\MailTransports;
use App\Mail\PdoMailBucket;
use App\Repository\PdoPickupOrderRepository;
use App\Repository\PdoScanRepository;
use App\Repository\PickupOrderRepository;
use App\Repository\ScanRepository;
use App\Service\AlertLog;
use App\Service\DeferredTasks;
use App\Service\DeliveryLog;
use App\Service\OrderDecider;
use App\Service\OrderEventHandler;
use App\Service\ShopifyHmac;
use App\Service\Template;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

/**
 * Construit l'application Slim et ses routes.
 */
final class AppFactory
{
    /**
     * @param (\Closure(): \PDO)|null $connect connexion à la base (remplaçable dans les tests)
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
        $connect ??= Services::connection($config);
        $tasks ??= new DeferredTasks();
        $orders ??= new PdoPickupOrderRepository($connect);
        $alerts ??= new AlertLog($projectRoot . '/var/log/alerts.log');
        $scans ??= new PdoScanRepository($connect);
        $templates ??= new Template($projectRoot . '/templates');
        $transport ??= MailTransports::fromConfig($config);
        $bucket ??= new PdoMailBucket($connect, $config->int('MAIL_MAX_PER_HOUR', 120));

        $processor = Services::pickupProcessor($config, $orders, $templates, $transport, $bucket, $alerts);

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

        $versionFile = $projectRoot . '/VERSION';
        $app->get('/health', new HealthController(
            $connect,
            is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : 'dev',
            strtolower((string) $config->get('MAIL_TRANSPORT', 'smtp')),
        ));
        $app->post('/webhooks/shopify/order-paid', new OrderEventController(
            $hmac,
            $handler,
            $deliveryLog,
            new DeliveryLog($projectRoot . '/var/log/deliveries-anomalies'),
        ));
        $app->get('/r/{token}', new ScanController(
            $orders,
            $scans,
            $templates,
            $config->get('SHOPIFY_STORE_HANDLE'),
        ));

        return $app;
    }
}
