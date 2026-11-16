<?php

// Lancé par PhpMailTransportTest avec -d sendmail_path=… (réglage impossible à modifier en cours d'exécution).
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Mail\MailMessage;
use App\Mail\PhpMailTransport;

try {
    (new PhpMailTransport('retrait@itsmadeleine.shop', "It's Madeleine"))->send(new MailMessage(
        'client@example.com',
        'Votre QR code de retrait — commande #1094',
        '<p>Présentez ce QR code</p><img src="cid:qr-retrait">',
        'Présentez ce QR code',
        ['qr-retrait' => ['data' => "\x89PNG-faux", 'filename' => 'qr-retrait.png', 'mime' => 'image/png']],
    ));
    echo 'ok';
} catch (\RuntimeException $e) {
    echo get_class($e), ': ', $e->getMessage();
}
