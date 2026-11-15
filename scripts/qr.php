<?php

declare(strict_types=1);

/**
 * PNG du QR de retrait pour un jeton, pour tester le scan au téléphone avant l'envoi du mail (M4).
 * Le jeton se lit dans phpMyAdmin : SELECT order_name, token FROM pickup_orders ORDER BY id DESC;
 *
 * Usage : php scripts/qr.php <jeton> [fichier.png] [url de l'app]
 *         (par défaut : qr-<8 premiers caractères>.png, https://app.itsmadeleine.shop)
 */

use App\Service\QrGenerator;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$token = $argv[1] ?? '';
if (!QrGenerator::isValidToken($token)) {
    fwrite(STDERR, "Usage : php scripts/qr.php <jeton de 32 caractères hexadécimaux> [fichier.png] [url de l'app]\n");
    exit(2);
}

$file = $argv[2] ?? sprintf('qr-%s.png', substr($token, 0, 8));
$qr = new QrGenerator($argv[3] ?? 'https://app.itsmadeleine.shop');

file_put_contents($file, $qr->png($token));
echo $qr->scanUrl($token) . "\n→ $file\n";
