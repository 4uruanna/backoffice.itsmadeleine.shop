<?php

declare(strict_types=1);

/**
 * Envoie un mail de test par SMTP et affiche le dialogue avec le serveur (identifiants masqués).
 * Sert à diagnostiquer l'envoi depuis commandes@itsmadeleine.shop (M0, avant M5).
 *
 * Paramètres lus dans .env : SMTP_HOST, SMTP_PORT, SMTP_ENCRYPTION (smtps, starttls ou vide),
 * SMTP_USER, SMTP_PASSWORD, MAIL_FROM, MAIL_FROM_NAME. SMTP_PASSWORD vide : demandé au clavier.
 *
 * Usage : php scripts/test-smtp.php <destinataire> [--host=ssl0.ovh.net] [--port=465] [--encryption=smtps]
 *         [--user=commandes@itsmadeleine.shop]
 */

use App\Config;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

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
$overrides = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(host|port|encryption|user)=(.*)$/', $arg, $m) === 1) {
        $overrides[$m[1]] = $m[2];
    } elseif ($to === null) {
        $to = $arg;
    }
}
if ($to === null || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage : php scripts/test-smtp.php <destinataire> [--host=…] [--port=…] [--encryption=smtps|starttls|] [--user=…]\n");
    exit(2);
}

$config = Config::fromEnvironment($root);
$host = $overrides['host'] ?? $config->require('SMTP_HOST');
$port = (int) ($overrides['port'] ?? $config->int('SMTP_PORT', 465));
$encryption = strtolower($overrides['encryption'] ?? (string) $config->get('SMTP_ENCRYPTION', ''));
$user = $overrides['user'] ?? $config->get('SMTP_USER');
$from = $config->get('MAIL_FROM', $user ?? '');
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

echo "Serveur : $host:$port, chiffrement : " . ($encryption === '' ? 'aucun' : $encryption)
    . ', identifiant : ' . ($user ?? '(sans authentification)') . "\nExpéditeur : $from → $to\n\n";

$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = $host;
$mail->Port = $port;
$mail->SMTPSecure = match ($encryption) {
    'smtps', 'ssl' => PHPMailer::ENCRYPTION_SMTPS,
    'starttls', 'tls' => PHPMailer::ENCRYPTION_STARTTLS,
    default => '',
};
$mail->SMTPAutoTLS = $mail->SMTPSecure !== '';
$mail->SMTPAuth = $user !== null;
$mail->Username = (string) $user;
$mail->Password = (string) $password;
$mail->Timeout = 15;
$mail->CharSet = PHPMailer::CHARSET_UTF8;
$mail->SMTPDebug = 2;

// Seules les commandes connues sont affichées en clair : identifiants (base64) et corps masqués,
// les lignes masquées consécutives regroupées en une seule.
$mail->Debugoutput = static function (string $line): void {
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

try {
    $mail->setFrom((string) $from, (string) $config->get('MAIL_FROM_NAME', ''));
    $mail->addAddress($to);
    $mail->Subject = 'Test SMTP itsmadeleine ' . gmdate('Y-m-d H:i') . ' UTC';
    $mail->Body = "Mail de test envoyé par scripts/test-smtp.php.\n";
    $mail->send();
    echo "\nOK : mail accepté par $host. Vérifier la réception (et les en-têtes SPF/DKIM).\n";
} catch (MailerException $e) {
    echo "\nÉCHEC : " . $e->getMessage() . "\n";
    exit(1);
}
