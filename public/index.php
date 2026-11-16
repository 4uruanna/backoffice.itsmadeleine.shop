<?php

declare(strict_types=1);

use App\AppFactory;
use App\Config;
use App\Service\DeferredTasks;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$tasks = new DeferredTasks();

AppFactory::create(Config::fromEnvironment($root), $root, tasks: $tasks)->run();

// Réponse envoyée à Shopify avant l'envoi du mail (OVH : PHP-FPM, fastcgi_finish_request disponible).
// Sans cette fonction (Apache mod_php en local), le traitement se fait avant la fin de la réponse.
if ($tasks->count() > 0) {
    ignore_user_abort(true);
    set_time_limit(60);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    $tasks->run();
}
