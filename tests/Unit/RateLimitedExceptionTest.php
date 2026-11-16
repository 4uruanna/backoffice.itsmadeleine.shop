<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Mail\RateLimitedException;
use PHPUnit\Framework\TestCase;

final class RateLimitedExceptionTest extends TestCase
{
    public function testRefusDeQuotaOvhReconnu(): void
    {
        // Message réel reçu le 16/11 (last_error).
        self::assertTrue(RateLimitedException::matches(
            'SMTP Error: data not accepted. SMTP server error: DATA END command failed Detail: Mail quota exceeded for '
            . 'commandes@itsmadeleine.shop: You have exceeded the limit of 200 messages per hour and per account. '
            . 'Please, re-try later. 221 Closing connection, please refrain from sending too many mails SMTP code: 550 '
            . 'Additional SMTP info: 5.7.0',
        ));
    }

    public function testAutresEchecsNonConcernes(): void
    {
        self::assertFalse(RateLimitedException::matches('SMTP Error: Could not authenticate.'));
        self::assertFalse(RateLimitedException::matches('SMTP Error: Could not connect to SMTP host. Failed to connect to server'));
        self::assertFalse(RateLimitedException::matches('SMTP Error: data not accepted. Detail: Message rejected as spam SMTP code: 554'));
    }
}
