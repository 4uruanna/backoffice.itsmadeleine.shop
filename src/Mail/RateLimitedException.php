<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Le serveur SMTP refuse l'envoi pour quota dépassé (OVH : « Mail quota exceeded …
 * 200 messages per hour and per account », vu le 16/11). Ce n'est pas un échec du mail :
 * la commande attend, sans compter d'essai.
 */
final class RateLimitedException extends \RuntimeException
{
    private const PATTERN = '/quota exceeded|too many (mails|messages)|rate limit/i';

    public static function matches(string $smtpError): bool
    {
        return preg_match(self::PATTERN, $smtpError) === 1;
    }
}
