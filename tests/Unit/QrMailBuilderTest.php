<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Mail\QrMailBuilder;
use App\Service\QrGenerator;
use App\Service\Template;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('gd')]
final class QrMailBuilderTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';
    private const STATUS_URL = 'https://itsmadeleine.shop/12345/orders/abcdef/authenticate?key=x&a=b';

    private function builder(): QrMailBuilder
    {
        return new QrMailBuilder(new Template(dirname(__DIR__, 2) . '/templates'), new QrGenerator('https://app.itsmadeleine.shop'));
    }

    public function testContenuDuMail(): void
    {
        $message = $this->builder()->build('client@example.com', '#1094', self::STATUS_URL, self::TOKEN);

        self::assertSame('client@example.com', $message->to);
        self::assertSame('Votre QR code de retrait — commande #1094', $message->subject);

        self::assertStringContainsString('Présentez ce QR code le jour du concert pour retirer votre commande.', $message->html);
        self::assertStringContainsString('src="cid:qr-retrait"', $message->html);
        self::assertStringContainsString('alt="QR code de retrait de la commande #1094"', $message->html);
        self::assertStringContainsString('Commande #1094', $message->html);
        self::assertStringContainsString('href="https://itsmadeleine.shop/12345/orders/abcdef/authenticate?key=x&amp;a=b"', $message->html);
        self::assertStringContainsString('Voir ma commande et le point de retrait', $message->html);
        self::assertDoesNotMatchRegularExpression('#<img[^>]+src="https?:#', $message->html, 'aucune image distante');
        self::assertStringContainsString('Vous pouvez aussi retirer votre commande en indiquant son numéro (#1094)', $message->html);
        self::assertStringContainsString("pièce d'identité de la personne au nom de laquelle elle a été passée", $message->html);
        self::assertStringContainsString('<a href="mailto:commandes@itsmadeleine.shop"', $message->html);
        self::assertStringContainsString('Une question ? Écrivez-nous à', $message->html);

        self::assertStringContainsString('Présentez ce QR code le jour du concert pour retirer votre commande.', $message->text);
        self::assertStringContainsString("Commande #1094\n", $message->text);
        self::assertStringContainsString(self::STATUS_URL . "\n", $message->text, 'URL complète, non échappée');
        self::assertStringNotContainsString('<', $message->text);
        self::assertStringContainsString("Vous pouvez aussi retirer votre commande en indiquant son numéro (#1094)\n", $message->text);
        self::assertStringContainsString("Une question ? Écrivez-nous à commandes@itsmadeleine.shop\n", $message->text);

        $image = $message->inlineImages[QrMailBuilder::QR_CID];
        self::assertSame('image/png', $image['mime']);
        self::assertStringStartsWith("\x89PNG", $image['data']);
    }

    public function testValeursEchappees(): void
    {
        $message = $this->builder()->build('client@example.com', '#1094<b>', null, self::TOKEN);

        self::assertStringNotContainsString('<b>', $message->html);
        self::assertStringContainsString('#1094&lt;b&gt;', $message->html);
    }

    public function testSansUrlDeStatutPasDeLien(): void
    {
        foreach ([null, 'javascript:alert(1)', 'http://non-chiffre.example'] as $url) {
            $message = $this->builder()->build('client@example.com', '#1094', $url, self::TOKEN);

            self::assertStringNotContainsString('Voir ma commande', $message->html);
            self::assertStringNotContainsString('Voir ma commande', $message->text);
            self::assertStringContainsString('Commande #1094', $message->text);
        }
    }

    public function testAdresseDeContactConfigurable(): void
    {
        $builder = new QrMailBuilder(
            new Template(dirname(__DIR__, 2) . '/templates'),
            new QrGenerator('https://app.itsmadeleine.shop'),
            'contact@example.com',
        );

        $message = $builder->build('client@example.com', '#1094', null, self::TOKEN);

        self::assertStringContainsString('mailto:contact@example.com', $message->html);
        self::assertStringContainsString('Écrivez-nous à contact@example.com', $message->text);
        self::assertStringNotContainsString('commandes@itsmadeleine.shop', $message->html);
    }
}
