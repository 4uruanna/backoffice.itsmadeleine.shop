<?php

declare(strict_types=1);

namespace App\Mail;

/** Mail prêt à partir : HTML + texte, images intégrées (CID). */
final readonly class MailMessage
{
    /**
     * @param array<string, array{data: string, filename: string, mime: string}> $inlineImages par CID
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $html,
        public string $text,
        public array $inlineImages = [],
    ) {
    }
}
