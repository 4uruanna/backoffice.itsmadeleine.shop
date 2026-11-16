<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Issue du traitement d'une ligne (PickupProcessor) : le cron (M7) s'arrête au premier
 * `rate_limited`, le journal n'utilise que le message (aucune donnée personnelle).
 */
final readonly class ProcessResult implements \Stringable
{
    /** Mail envoyé. */
    public const SENT = 'sent';
    /** Rien à faire : ligne déjà prise, envoyée ou révoquée. */
    public const SKIPPED = 'skipped';
    /** Laissée en `pending` sans essai : hors liste d'autorisation, sans e-mail, révoquée avant l'envoi. */
    public const WAITING = 'waiting';
    /** Bucket vide ou quota du serveur d'envoi : en `pending` / `rate_limited`. */
    public const RATE_LIMITED = 'rate_limited';
    /** Échec d'envoi, rejouable (`failed`). */
    public const FAILED = 'failed';
    /** Échec définitif (`error` / `max_attempts`). */
    public const ERROR = 'error';

    /** @param self::SENT|self::SKIPPED|self::WAITING|self::RATE_LIMITED|self::FAILED|self::ERROR $outcome */
    public function __construct(public string $outcome, public string $message)
    {
    }

    public function __toString(): string
    {
        return $this->message;
    }
}
