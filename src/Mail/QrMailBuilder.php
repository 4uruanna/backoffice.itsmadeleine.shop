<?php

declare(strict_types=1);

namespace App\Mail;

use App\Service\QrGenerator;
use App\Service\Template;

/**
 * Mail au client (M5) : phrase de retrait, QR en image intégrée, numéro de commande,
 * lien vers la page de statut. Aucune image distante, aucun pixel de suivi.
 */
final readonly class QrMailBuilder
{
    public const QR_CID = 'qr-retrait';

    public function __construct(private Template $templates, private QrGenerator $qr)
    {
    }

    public function build(string $to, string $orderName, ?string $statusPageUrl, string $token): MailMessage
    {
        $variables = [
            'orderName' => $orderName,
            // Seules les URL https sont mises en lien (valeur venue de Shopify, par prudence).
            'statusPageUrl' => $statusPageUrl !== null && str_starts_with($statusPageUrl, 'https://') ? $statusPageUrl : null,
            'qrCid' => self::QR_CID,
        ];

        return new MailMessage(
            $to,
            sprintf('Votre QR code de retrait — commande %s', $orderName),
            $this->templates->render('mail/qr.html.php', $variables),
            $this->templates->render('mail/qr.txt.php', $variables),
            [self::QR_CID => [
                'data' => $this->qr->png($token),
                'filename' => 'qr-retrait.png',
                'mime' => 'image/png',
            ]],
        );
    }
}
