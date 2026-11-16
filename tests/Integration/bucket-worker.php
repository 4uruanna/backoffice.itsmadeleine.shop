<?php

// Processus du test de concurrence du bucket : attend l'heure de départ commune, puis prend un jeton.
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;
use App\Database;
use App\Mail\PdoMailBucket;

[, $startAt, $max] = $argv;

$pdo = Database::connect(new Config([
    'DB_HOST' => getenv('TEST_DB_HOST'),
    'DB_PORT' => getenv('TEST_DB_PORT') ?: '3306',
    'DB_NAME' => 'backoffice_test',
    'DB_USER' => getenv('TEST_DB_USER') ?: 'root',
    'DB_PASSWORD' => getenv('TEST_DB_PASSWORD') ?: '',
]));
$bucket = new PdoMailBucket(fn () => $pdo, (int) $max);

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

echo $bucket->tryAcquire(null, 'qr') ? 'ok' : 'vide';
