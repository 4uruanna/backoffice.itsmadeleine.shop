<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envoi SMTP (PHPMailer). OVH : ssl0.ovh.net, 465, SMTPS, identifiant = adresse complète.
 * Local : Mailpit, port 1025, sans chiffrement ni authentification.
 */
final readonly class SmtpTransport implements MailTransport
{
    public function __construct(
        private string $host,
        private int $port,
        private string $encryption,
        private ?string $user,
        private ?string $password,
        private string $fromAddress,
        private string $fromName,
        private int $timeout = 10,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            // Pas de require() : la configuration n'est exigée qu'à l'envoi, pas à chaque requête.
            (string) $config->get('SMTP_HOST', ''),
            $config->int('SMTP_PORT', 465),
            strtolower((string) $config->get('SMTP_ENCRYPTION', '')),
            $config->get('SMTP_USER'),
            $config->get('SMTP_PASSWORD'),
            (string) $config->get('MAIL_FROM', ''),
            (string) $config->get('MAIL_FROM_NAME', ''),
        );
    }

    public function send(MailMessage $message): void
    {
        if ($this->host === '' || $this->fromAddress === '') {
            throw new \RuntimeException('Configuration manquante : SMTP_HOST ou MAIL_FROM');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->SMTPSecure = match ($this->encryption) {
                'smtps', 'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'starttls', 'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            $mail->SMTPAutoTLS = $mail->SMTPSecure !== '';
            $mail->SMTPAuth = $this->user !== null;
            $mail->Username = (string) $this->user;
            $mail->Password = (string) $this->password;
            $mail->Timeout = $this->timeout;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            // EHLO et Message-ID au nom du domaine expéditeur, pas du serveur mutualisé.
            $mail->Hostname = substr((string) strrchr($this->fromAddress, '@'), 1) ?: 'localhost';

            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->addAddress($message->to);
            $mail->Subject = $message->subject;
            $mail->isHTML();
            $mail->Body = $message->html;
            $mail->AltBody = $message->text;
            foreach ($message->inlineImages as $cid => $image) {
                $mail->addStringEmbeddedImage($image['data'], $cid, $image['filename'], PHPMailer::ENCODING_BASE64, $image['mime']);
            }

            $mail->send();
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->redact($e->getMessage()), 0, $e);
        } finally {
            $mail->smtpClose();
        }
    }

    /** Jamais de mot de passe dans last_error ni dans les journaux. */
    private function redact(string $message): string
    {
        if ($this->password === null || $this->password === '') {
            return $message;
        }

        return str_replace([$this->password, base64_encode($this->password)], '***', $message);
    }
}
