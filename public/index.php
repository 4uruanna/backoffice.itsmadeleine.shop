<?php

declare(strict_types=1);

use App\AppFactory;
use App\Config;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

date_default_timezone_set('UTC');

AppFactory::create(Config::fromEnvironment($root), $root)->run();
