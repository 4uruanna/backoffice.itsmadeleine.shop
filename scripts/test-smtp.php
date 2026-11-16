<?php

declare(strict_types=1);

/**
 * Envoie un mail de test par SMTP et affiche le dialogue avec le serveur (identifiants et corps masqués).
 * Passe par SmtpTransport, le même code que l'API.
 *
 * Paramètres lus dans .env : SMTP_HOST, SMTP_PORT, SMTP_ENCRYPTION (smtps, starttls ou vide),
 * SMTP_USER, SMTP_PASSWORD, MAIL_FROM, MAIL_FROM_NAME. SMTP_PASSWORD vide : demandé au clavier.
 *
 * Usage : php scripts/test-smtp.php <destinataire> [--qr] [--host=ssl0.ovh.net] [--port=465]
 *         [--encryption=smtps] [--user=commandes@itsmadeleine.shop] [--from=…] [--from-name=…]
 *   --qr : envoie le vrai mail de retrait (QR intégré) au lieu d'un texte simple ;
 *          --order=#1234 et --status-url=https://… reproduisent le mail d'une commande précise
 *          (valeurs order_name et status_page_url de pickup_orders).
 */

use App\Config;
use App\Mail\MailMessage;
use App\Mail\QrMailBuilder;
use App\Mail\SmtpTransport;
use App\Service\QrGenerator;
use App\Service\Template;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/ absent : lancer d'abord `docker compose run --rm php composer install`.\n");
    exit(1);
}
require $root . '/vendor/autoload.php';

$to = null;
$qr = false;
$overrides = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--qr') {
        $qr = true;
    } elseif (preg_match('/^--(host|port|encryption|user|from|from-name|order|status-url)=(.*)$/s', $arg, $m) === 1) {
        $overrides[$m[1]] = $m[2];
    } elseif ($to === null) {
        $to = $arg;
    }
}
if ($to === null || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage : php scripts/test-smtp.php <destinataire> [--qr] [--host=…] [--port=…] [--encryption=smtps|starttls|] [--user=…] [--from=…] [--from-name=…] [--order=#1234] [--status-url=https://…]\n");
    exit(2);
}

$config = Config::fromEnvironment($root);
$user = $overrides['user'] ?? $config->get('SMTP_USER');
$password = $config->get('SMTP_PASSWORD');

if ($user !== null && $password === null) {
    // Saisie masquée : le mot de passe ne passe ni par l'historique du shell ni par l'écran.
    fwrite(STDOUT, "Mot de passe SMTP de $user : ");
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
    }
    fwrite(STDOUT, "\n");
}

$host = $overrides['host'] ?? (string) $config->get('SMTP_HOST', '');
$port = (int) ($overrides['port'] ?? $config->int('SMTP_PORT', 465));
$encryption = strtolower($overrides['encryption'] ?? (string) $config->get('SMTP_ENCRYPTION', ''));
$from = $overrides['from'] ?? (string) $config->get('MAIL_FROM', (string) $user);
$fromName = $overrides['from-name'] ?? (string) $config->get('MAIL_FROM_NAME', '');

echo "Serveur : $host:$port, chiffrement : " . ($encryption === '' ? 'aucun' : $encryption)
    . ', identifiant : ' . ($user ?? '(sans authentification)') . "\nExpéditeur : $fromName <$from> → $to\n"
    . 'Mail : ' . ($qr
        ? sprintf('mail de retrait réel (HTML + QR intégré), commande %s, lien de statut %s', $overrides['order'] ?? '#TEST', $overrides['status-url'] ?? 'https://itsmadeleine.shop')
        : 'texte simple') . "\n\n";

// Seules les commandes connues sont affichées en clair : identifiants (base64) et corps masqués,
// les lignes masquées consécutives regroupées en une seule.
$debug = static function (string $line): void {
    static $masked = false;
    $line = rtrim($line);
    if (preg_match('/^CLIENT -> SERVER: ?(.*)$/s', $line, $m) === 1
        && preg_match('/^(EHLO|HELO|STARTTLS|AUTH [A-Z0-9-]+$|MAIL FROM|RCPT TO|DATA$|QUIT|RSET)/', $m[1]) !== 1
    ) {
        if (!$masked) {
            echo "CLIENT -> SERVER: [masqué]\n";
        }
        $masked = true;

        return;
    }
    $masked = false;
    echo $line, "\n";
};

$transport = (new SmtpTransport($host, $port, $encryption, $user, $password, $from, $fromName, 15))->withDebug($debug);

$message = $qr
    ? (new QrMailBuilder(
        new Template($root . '/templates'),
        new QrGenerator((string) $config->get('APP_URL', 'https://app.itsmadeleine.shop')),
    ))->build($to, $overrides['order'] ?? '#TEST', $overrides['status-url'] ?? 'https://itsmadeleine.shop', QrGenerator::newToken())
    : new MailMessage($to, 'Test SMTP itsmadeleine ' . gmdate('Y-m-d H:i') . ' UTC', '', "Mail de test envoyé par scripts/test-smtp.php.\n");

try {
    $transport->send($message);
    echo "\nOK : mail accepté par $host. Vérifier la réception (et les en-têtes SPF/DKIM).\n";
} catch (\RuntimeException $e) {
    echo "\nÉCHEC : " . $e->getMessage() . "\n";
    exit(1);
}
