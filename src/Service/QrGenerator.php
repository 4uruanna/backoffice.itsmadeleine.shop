<?php

declare(strict_types=1);

namespace App\Service;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * QR code de retrait (M4) : PNG de l'URL de scan, généré en mémoire.
 *
 * URL d'environ 56 caractères → version 4 (33 modules) en correction M ;
 * 33 + 2 × 4 de marge = 41 modules × 8 px = 328 px.
 */
final readonly class QrGenerator
{
    public const TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';

    private string $baseUrl;

    public function __construct(string $appUrl)
    {
        $this->baseUrl = rtrim($appUrl, '/');
    }

    /** Jeton de 32 caractères hexadécimaux (128 bits). */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isValidToken(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    public function scanUrl(string $token): string
    {
        if (!self::isValidToken($token)) {
            throw new \InvalidArgumentException('Jeton de retrait invalide');
        }

        return $this->baseUrl . '/r/' . $token;
    }

    /** @return string contenu binaire du PNG */
    public function png(string $token): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'scale' => 8,
            'quietzoneSize' => 4,
            'addQuietzone' => true,
            // Modules carrés, noir sur blanc : le plus lisible pour un appareil photo.
            'drawCircularModules' => false,
            'imageTransparent' => false,
        ]);

        $png = (new QRCode($options))->render($this->scanUrl($token));
        \assert(is_string($png));

        return $png;
    }
}
