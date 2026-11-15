<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\QrGenerator;
use chillerlan\QRCode\QRCode;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class QrGeneratorTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';

    public function testNouveauJeton(): void
    {
        $token = QrGenerator::newToken();

        self::assertTrue(QrGenerator::isValidToken($token));
        self::assertNotSame($token, QrGenerator::newToken());
    }

    public function testFormatDuJeton(): void
    {
        self::assertTrue(QrGenerator::isValidToken(self::TOKEN));
        self::assertFalse(QrGenerator::isValidToken(strtoupper(self::TOKEN)), 'majuscules refusées');
        self::assertFalse(QrGenerator::isValidToken(substr(self::TOKEN, 1)), '31 caractères');
        self::assertFalse(QrGenerator::isValidToken(self::TOKEN . '0'), '33 caractères');
        self::assertFalse(QrGenerator::isValidToken(self::TOKEN . "\n"), 'retour à la ligne final');
        self::assertFalse(QrGenerator::isValidToken(''));
    }

    public function testUrlDeScan(): void
    {
        $qr = new QrGenerator('https://app.itsmadeleine.shop/');

        self::assertSame('https://app.itsmadeleine.shop/r/' . self::TOKEN, $qr->scanUrl(self::TOKEN));
    }

    public function testJetonInvalideRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new QrGenerator('https://app.itsmadeleine.shop'))->scanUrl('../admin');
    }

    #[RequiresPhpExtension('gd')]
    public function testPngRelisible(): void
    {
        $png = (new QrGenerator('https://app.itsmadeleine.shop'))->png(self::TOKEN);

        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $size = getimagesizefromstring($png);
        self::assertIsArray($size);
        self::assertSame($size[0], $size[1], 'carré');
        self::assertGreaterThanOrEqual(280, $size[0]);
        self::assertLessThanOrEqual(400, $size[0]);

        $decoded = (new QRCode())->readFromBlob($png);
        self::assertSame('https://app.itsmadeleine.shop/r/' . self::TOKEN, (string) $decoded);
        self::assertSame('M', $decoded->eccLevel->__toString());
    }
}
