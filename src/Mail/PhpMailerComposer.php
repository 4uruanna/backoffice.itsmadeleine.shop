<?php

declare(strict_types=1);

namespace App\Mail;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Compose le même mail pour tous les transports PHPMailer (SMTP, mail() de l'hébergement) :
 * expéditeur, HTML + texte, images intégrées (CID), UTF-8, Message-ID au domaine expéditeur.
 */
final class PhpMailerComposer
{
    public static function compose(PHPMailer $mail, MailMessage $message, string $fromAddress, string $fromName): void
    {
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        // EHLO et Message-ID au nom du domaine expéditeur, pas du serveur mutualisé.
        $mail->Hostname = self::domain($fromAddress);

        $mail->setFrom($fromAddress, $fromName);
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
    }

    public static function domain(string $address): string
    {
        return substr((string) strrchr($address, '@'), 1) ?: 'localhost';
    }

    /** Message d'erreur sur une ligne, réponse du serveur comprise (ErrorInfo). */
    public static function errorDetail(PHPMailer $mail, \Throwable $e): string
    {
        $detail = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();

        return trim((string) preg_replace('/\s+/', ' ', $detail));
    }
}
