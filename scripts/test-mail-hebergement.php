<?php

/**
 * Test ponctuel de la fonction mail() d'un hébergement OVH (16/11), avant une migration.
 * Autonome : aucune dépendance, aucune base, aucun .env.
 *
 * 1. Renseigner $to ci-dessous (ton adresse Gmail).
 * 2. Déposer ce fichier par FTP à la racine web de l'hébergement testé, sous un nom difficile
 *    à deviner (ex. test-mail-8f3k2.php).
 * 3. L'ouvrir UNE fois dans le navigateur, puis LE SUPPRIMER du serveur.
 * 4. Vérifier : réception dans Gmail (spams compris), « Afficher l'original » → SPF, DKIM, DMARC ;
 *    espace client OVH → Scripts e-mails (avis de non-remise éventuel).
 */

declare(strict_types=1);

$to = '';                                  // ← ton adresse Gmail
$from = 'retrait@itsmadeleine.shop';
$fromName = "It's Madeleine";

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    echo "Renseigner \$to dans le fichier avant de l'ouvrir.\n";
    exit;
}

// Un seul envoi par minute, pour ne pas servir de relais si l'URL était découverte.
$lock = sys_get_temp_dir() . '/test-mail-hebergement.lock';
if (is_file($lock) && time() - (int) filemtime($lock) < 60) {
    echo "Déjà envoyé il y a moins d'une minute.\n";
    exit;
}
touch($lock);

$subject = '=?UTF-8?B?' . base64_encode('Test mail() hébergement — ' . gmdate('H:i:s') . ' UTC') . '?=';
$headers = implode("\r\n", [
    'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <$from>",
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
]);
$body = "Test de la fonction mail() de l'hébergement.\nServeur : " . gethostname() . "\nPHP : " . PHP_VERSION . "\n";

// -f : expéditeur d'enveloppe (Return-Path) sur le domaine, comme le fera l'API.
$ok = mail($to, $subject, $body, $headers, '-f' . $from);

echo $ok
    ? "mail() a accepté le message. Vérifier Gmail et les Scripts e-mails, puis SUPPRIMER ce fichier.\n"
    : "mail() a refusé le message. SUPPRIMER ce fichier.\n";
