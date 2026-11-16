<?php

declare(strict_types=1);

/*
 * Cron de rattrapage (M7), toutes les heures. Tâche OVH : backoffice/cron/retry.php,
 * même version de PHP que le site (8.5), journal activé.
 * En local : docker compose exec php php cron/retry.php
 *
 * Code de sortie : 0 (passage fait, ou un autre passage en cours), 1 (erreur, détail dans le journal).
 */

use App\Config;
use App\Service\AlertLog;
use App\Services;

// Jamais depuis le web, même si le dossier venait à être exposé.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$version = is_file($root . '/VERSION') ? trim((string) file_get_contents($root . '/VERSION')) : 'dev';
echo sprintf("cron retry %s (version %s)\n", gmdate('Y-m-d\TH:i:s\Z'), $version);

try {
    $config = Config::fromEnvironment($root);
    $connect = Services::connection($config);
    $pdo = $connect();

    // Un seul passage à la fois (tâche relancée alors que la précédente tourne encore).
    if ((int) $pdo->query("SELECT GET_LOCK('cron_retry', 0)")->fetchColumn() !== 1) {
        echo "un autre passage est en cours, rien à faire\n";
        exit(0);
    }

    try {
        foreach (Services::retryRunner($config, $root, $connect)->run() as $line) {
            echo $line, "\n";
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('cron_retry')");
    }
} catch (\Throwable $e) {
    $message = mb_substr($e->getMessage(), 0, 300);
    echo 'ERREUR : ', $message, "\n";
    (new AlertLog($root . '/var/log/alerts.log'))->alert('cron_failed', 'Cron de rattrapage en échec', [
        'error' => $message,
    ]);
    exit(1);
}

echo "fin\n";
