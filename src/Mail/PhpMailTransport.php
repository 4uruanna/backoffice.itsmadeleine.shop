<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envoi par la fonction mail() de PHP, c'est-à-dire par le service « scripts e-mails »
 * de l'hébergement OVH (16/11) : quota propre à l'hébergement, non partagé avec d'autres
 * clients, contrairement au SMTP refusé depuis l'IP mutualisée.
 *
 * L'expéditeur d'enveloppe (-f) est l'adresse d'envoi, sur le domaine du site.
 */
final readonly class PhpMailTransport implements MailTransport
{
    public function __construct(private string $fromAddress, private string $fromName)
    {
    }

    public static function fromConfig(Config $config): self
    {
        return new self((string) $config->get('MAIL_FROM', ''), (string) $config->get('MAIL_FROM_NAME', ''));
    }

    public function send(MailMessage $message): void
    {
        if ($this->fromAddress === '') {
            throw new \RuntimeException('Configuration manquante : MAIL_FROM');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isMail();
            PhpMailerComposer::compose($mail, $message, $this->fromAddress, $this->fromName);
            // Expéditeur d'enveloppe : PHPMailer le passe à mail() avec -f (Return-Path).
            $mail->Sender = $this->fromAddress;

            $mail->send();
        } catch (\Throwable $e) {
            $detail = 'mail() : ' . PhpMailerComposer::errorDetail($mail, $e);
            if (RateLimitedException::matches($detail)) {
                throw new RateLimitedException($detail, 0, $e);
            }
            throw new \RuntimeException($detail, 0, $e);
        }
    }
}
