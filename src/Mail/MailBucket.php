<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Limite d'envoi (M6) : au plus MAIL_MAX_PER_HOUR mails sur toute période de 60 minutes,
 * mails clients et alertes confondus. Un jeton pris = une ligne dans `mail_log`.
 */
interface MailBucket
{
    public const KIND_QR = 'qr';
    public const KIND_ALERT = 'alert';

    /**
     * Prend un jeton s'il en reste. Un jeton pris reste consommé même si l'envoi échoue
     * (prudence vis-à-vis du quota d'OVH).
     *
     * @param self::KIND_* $kind
     * @return bool false : bucket vide (ou verrou indisponible), ne pas envoyer
     */
    public function tryAcquire(?int $pickupOrderId, string $kind): bool;
}
