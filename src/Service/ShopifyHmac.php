<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Vérifie la signature des livraisons Shopify (webhooks et Events) :
 * HMAC-SHA256 du corps brut avec le secret client de l'app, encodé en base64.
 */
final class ShopifyHmac
{
    /** En-têtes possibles, du plus récent au plus ancien format. */
    public const HEADERS = ['Shopify-Hmac-Sha256', 'X-Shopify-Hmac-Sha256'];

    public function __construct(private readonly string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Secret client Shopify vide');
        }
    }

    public function sign(string $rawBody): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $this->secret, true));
    }

    public function isValid(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        return hash_equals($this->sign($rawBody), $signature);
    }

    public function isValidRequest(ServerRequestInterface $request, string $rawBody): bool
    {
        return $this->isValid($rawBody, self::signatureFrom($request));
    }

    public static function signatureFrom(ServerRequestInterface $request): ?string
    {
        foreach (self::HEADERS as $header) {
            $value = $request->getHeaderLine($header);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
