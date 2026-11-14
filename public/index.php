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

// M3 : tâches exécutées à la fin de la requête. M7 : fastcgi_finish_request() avant,
// pour répondre à Shopify sans attendre l'envoi du mail.
$tasks->run();
