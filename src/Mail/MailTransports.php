<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config;

/** Choix du transport par MAIL_TRANSPORT : `smtp` (défaut) ou `phpmail` (mail() de l'hébergement). */
final class MailTransports
{
    public static function fromConfig(Config $config): MailTransport
    {
        return match (strtolower((string) $config->get('MAIL_TRANSPORT', 'smtp'))) {
            'phpmail', 'mail' => PhpMailTransport::fromConfig($config),
            'smtp' => SmtpTransport::fromConfig($config),
            // Pas d'exception ici : une faute de frappe dans .env ne doit pas arrêter toute l'API
            // (scan, réception des Events). Seuls les envois échouent, avec un message clair.
            default => new class implements MailTransport {
                public function send(MailMessage $message): void
                {
                    throw new \RuntimeException('MAIL_TRANSPORT inconnu (attendu : smtp ou phpmail)');
                }
            },
        };
    }
}
