<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Résultat de la table de décision de M3. Chaque action se traduit par une
 * requête idempotente : rejouer une livraison ne change rien.
 */
final readonly class Decision
{
    public const RECORD = 'record';                 // payée : reprise d'une ligne payload_errors, sinon insertion
    public const RECORD_PAYLOAD_ERROR = 'payload_error'; // errors : insertion error/payload_errors si absente
    public const REVOKE = 'revoke';                 // annulée ou remboursée en totalité
    public const NOTHING = 'nothing';

    public const STATUS_PENDING = 'pending';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_ERROR = 'error';

    public const ERROR_PAYLOAD = 'payload_errors';
    public const ERROR_NO_EMAIL = 'no_email';

    private function __construct(
        public string $action,
        public string $reason,
        public ?string $status = null,
        public ?string $errorCode = null,
        public ?string $revokedReason = null,
    ) {
    }

    public static function record(string $status, ?string $errorCode, string $reason): self
    {
        return new self(self::RECORD, $reason, $status, $errorCode);
    }

    public static function payloadError(string $reason): self
    {
        return new self(self::RECORD_PAYLOAD_ERROR, $reason, self::STATUS_ERROR, self::ERROR_PAYLOAD);
    }

    public static function revoke(string $revokedReason): self
    {
        return new self(self::REVOKE, $revokedReason, revokedReason: $revokedReason);
    }

    public static function nothing(string $reason): self
    {
        return new self(self::NOTHING, $reason);
    }
}
