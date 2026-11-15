<?php

declare(strict_types=1);

namespace App;

use App\Controller\HealthController;
use App\Controller\OrderEventController;
use App\Controller\ScanController;
use App\Repository\PdoPickupOrderRepository;
use App\Repository\PdoScanRepository;
use App\Repository\PickupOrderRepository;
use App\Repository\ScanRepository;
use App\Service\AlertLog;
use App\Service\DeferredTasks;
use App\Service\DeliveryLog;
use App\Service\OrderDecider;
use App\Service\OrderEventHandler;
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
    ): App {
        $connect ??= self::lazyConnection($config);
        $tasks ??= new DeferredTasks();
        $orders ??= new PdoPickupOrderRepository($connect);
        $alerts ??= new AlertLog($projectRoot . '/var/log/alerts.log');
        $scans ??= new PdoScanRepository($connect);
        $templates ??= new Template($projectRoot . '/templates');

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
            // Traitement d'une commande `pending` : jeton (M4), mail (M5), bucket (M6), verrou (M7).
            // Le jeton est enregistré avant tout envoi : un nouvel essai réutilise le même QR.
            static function (int $pickupOrderId) use ($orders): void {
                $token = $orders->assignToken($pickupOrderId, QrGenerator::newToken());
                error_log(sprintf(
                    'events: ligne %d %s, mail à venir (M5-M7)',
                    $pickupOrderId,
                    $token === null ? 'introuvable' : 'avec jeton',
                ));
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

    /** @return \Closure(): PDO */
    private static function lazyConnection(Config $config): \Closure
    {
        $pdo = null;

        return static function () use ($config, &$pdo): PDO {
            return $pdo ??= Database::connect($config);
        };
    }
}
