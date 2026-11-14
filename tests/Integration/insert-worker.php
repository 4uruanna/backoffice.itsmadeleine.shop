<?php

// Processus du test de concurrence : attend l'heure de départ commune, puis insère la commande.
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;
use App\Database;
use App\Domain\OrderSnapshot;
use App\Repository\PdoPickupOrderRepository;

[, $startAt, $orderId, $worker] = $argv;

$config = new Config([
    'DB_HOST' => getenv('TEST_DB_HOST'),
    'DB_PORT' => getenv('TEST_DB_PORT') ?: '3306',
    'DB_NAME' => 'backoffice_test',
    'DB_USER' => getenv('TEST_DB_USER') ?: 'root',
    'DB_PASSWORD' => getenv('TEST_DB_PASSWORD') ?: '',
]);
$pdo = Database::connect($config);
$repository = new PdoPickupOrderRepository(fn () => $pdo);
$order = new OrderSnapshot((int) $orderId, '#1094', 'client@example.com', null, 'PAID', null, ['retrait-qr']);

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

$id = $repository->insertIfAbsent((int) $orderId, $order, 'pending', null, null, "wh-$worker");
echo $id === null ? 'doublon' : "insert:$id";
