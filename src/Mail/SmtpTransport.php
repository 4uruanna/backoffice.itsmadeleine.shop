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
        /** @var (\Closure(string): void)|null dialogue SMTP (scripts/test-smtp.php) ; null en production */
        private ?\Closure $debug = null,
    ) {
    }

    public function withDebug(\Closure $debug): self
    {
        return new self(
            $this->host, $this->port, $this->encryption, $this->user, $this->password,
            $this->fromAddress, $this->fromName, $this->timeout, $debug,
        );
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
            if ($this->debug !== null) {
                $mail->SMTPDebug = 2;
                $mail->Debugoutput = $this->debug;
            }

            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->addAddress($message->to);
            $mail->Subject = $message->subject;
            if ($message->html === '') {
                $mail->Body = $message->text;
            } else {
                $mail->isHTML();
                $mail->Body = $message->html;
                $mail->AltBody = $message->text;
            }
            foreach ($message->inlineImages as $cid => $image) {
                $mail->addStringEmbeddedImage($image['data'], $cid, $image['filename'], PHPMailer::ENCODING_BASE64, $image['mime']);
            }

            $mail->send();
        } catch (\Throwable $e) {
            // ErrorInfo contient la réponse du serveur (code et motif), absente du message de l'exception.
            $detail = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
            throw new \RuntimeException($this->redact(trim((string) preg_replace('/\s+/', ' ', $detail))), 0, $e);
        } finally {
            $mail->smtpClose();
        }
    }

    /** Jamais de mot de passe dans last_error ni dans les journaux. */
    private function redact(string $message): string
    {
        // PHPMailer ne met pas le mot de passe dans ses erreurs : ceci n'est qu'une précaution.
        // En dessous de 6 caractères, le remplacement abîmerait le texte (mots qui contiennent le mot de passe).
        if ($this->password === null || strlen($this->password) < 6) {
            return $message;
        }

        return str_replace([$this->password, base64_encode($this->password)], '***', $message);
    }
}
