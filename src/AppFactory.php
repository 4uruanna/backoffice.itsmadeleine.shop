<?php

declare(strict_types=1);

namespace App;

use App\Controller\DeliveryProbeController;
use App\Controller\HealthController;
use App\Service\ShopifyHmac;
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
     */
    public static function create(Config $config, string $projectRoot, ?\Closure $connect = null): App
    {
        $connect ??= self::lazyConnection($config);

        $secret = $config->get('SHOPIFY_CLIENT_SECRET');
        $hmac = $secret === null ? null : new ShopifyHmac($secret);

        $app = SlimAppFactory::create();
        $app->addRoutingMiddleware();

        $isProduction = $config->get('APP_ENV', 'production') === 'production';
        // Erreurs détaillées à l'écran hors production ; journal sans trace complète.
        $app->addErrorMiddleware(!$isProduction, true, false);

        $app->get('/health', new HealthController($connect));
        $app->post(
            '/webhooks/shopify/order-paid',
            new DeliveryProbeController($hmac, $projectRoot . '/var/log/deliveries'),
        );

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
