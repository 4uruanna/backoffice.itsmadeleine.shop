<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\OrderSnapshot;
use App\Mail\QrMailBuilder;
use App\Service\PickupProcessor;
use App\Service\QrGenerator;
use App\Service\Template;
use App\Tests\Support\InMemoryPickupOrderRepository;
use App\Tests\Support\SpyAlertLog;
use App\Tests\Support\SpyMailTransport;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('gd')]
final class PickupProcessorTest extends TestCase
{
    private const SHOPIFY_ID = 8264632992077;

    private InMemoryPickupOrderRepository $orders;
    private SpyMailTransport $transport;
    private SpyAlertLog $alerts;
    private int $id;

    protected function setUp(): void
    {
        $this->orders = new InMemoryPickupOrderRepository();
        $this->transport = new SpyMailTransport();
        $this->alerts = new SpyAlertLog();
        $this->id = (int) $this->orders->insertIfAbsent(
            self::SHOPIFY_ID,
            new OrderSnapshot(self::SHOPIFY_ID, '#1094', 'Client@Example.com', 'https://example.myshopify.com/s', 'PAID', null, ['retrait-qr']),
            'pending', null, null, 'wh',
        );
    }

    /** @param list<string> $allowlist */
    private function processor(array $allowlist = []): PickupProcessor
    {
        return new PickupProcessor(
            $this->orders,
            new QrMailBuilder(new Template(dirname(__DIR__, 2) . '/templates'), new QrGenerator('https://app.itsmadeleine.shop')),
            $this->transport,
            $this->alerts,
            3,
            $allowlist,
        );
    }

    private function row(): array
    {
        return (array) $this->orders->findById($this->id);
    }

    public function testEnvoiPuisStatutSent(): void
    {
        $summary = $this->processor()->process($this->id);

        self::assertSame("ligne {$this->id} : mail envoyé", $summary);
        self::assertSame('sent', $this->row()['status']);
        self::assertNotNull($this->row()['sent_at']);
        self::assertNull($this->row()['locked_at']);
        self::assertCount(1, $this->transport->sent);

        $message = $this->transport->sent[0];
        self::assertSame('Client@Example.com', $message->to);
        self::assertSame('Votre QR code de retrait — commande #1094', $message->subject);
        self::assertArrayHasKey(QrMailBuilder::QR_CID, $message->inlineImages);
        self::assertSame(
            'https://app.itsmadeleine.shop/r/' . $this->row()['token'],
            (string) (new \chillerlan\QRCode\QRCode())->readFromBlob($message->inlineImages[QrMailBuilder::QR_CID]['data']),
            'le QR du mail pointe vers le jeton enregistré',
        );
    }

    public function testJamaisDeuxEnvois(): void
    {
        $this->processor()->process($this->id);
        $summary = $this->processor()->process($this->id);

        self::assertStringContainsString('rien à traiter', $summary);
        self::assertCount(1, $this->transport->sent);
    }

    public function testCommandeRevoqueeJamaisEnvoyee(): void
    {
        $this->orders->revoke(self::SHOPIFY_ID, 'cancelled');

        $this->processor()->process($this->id);

        self::assertSame([], $this->transport->sent);
        self::assertSame('pending', $this->row()['status'], 'reste en l\'état');
    }

    public function testAnnuleePendantLeTraitementAucunMail(): void
    {
        // Annulation reçue entre la prise en charge et l'envoi (après l'attribution du jeton).
        $this->orders->afterAssignToken = fn () => $this->orders->revoke(self::SHOPIFY_ID, 'cancelled');

        $summary = $this->processor()->process($this->id);

        self::assertStringContainsString('révoquée avant l\'envoi', $summary);
        self::assertSame([], $this->transport->sent);
        self::assertSame('pending', $this->row()['status']);
        self::assertSame('revoked', $this->row()['pending_reason']);
        self::assertStringContainsString('rien à traiter', $this->processor()->process($this->id), 'jamais repris');
    }

    public function testEchecPuisNouvelEssaiAvecLeMemeQr(): void
    {
        $this->transport->failures = ['SMTP Error: Could not connect to SMTP host.'];

        $summary = $this->processor()->process($this->id);
        $token = $this->row()['token'];

        self::assertStringContainsString('échec d\'envoi (failed)', $summary);
        self::assertSame('failed', $this->row()['status']);
        self::assertSame(1, $this->row()['attempts']);
        self::assertSame('SMTP Error: Could not connect to SMTP host.', $this->row()['last_error']);

        $this->processor()->process($this->id);

        self::assertSame('sent', $this->row()['status']);
        self::assertSame($token, $this->row()['token'], 'même jeton, donc même QR');
        self::assertSame([], $this->alerts->alerts);
    }

    public function testTroisiemeEchecPasseEnErreurEtAlerte(): void
    {
        $this->transport->failures = ['e1', 'e2', 'e3'];
        $processor = $this->processor();

        $processor->process($this->id);
        $processor->process($this->id);
        $summary = $processor->process($this->id);

        self::assertStringContainsString('(error)', $summary);
        self::assertSame('error', $this->row()['status']);
        self::assertSame('max_attempts', $this->row()['error_code']);
        self::assertSame(3, $this->row()['attempts']);
        self::assertSame(['max_attempts'], $this->alerts->codes());
        self::assertStringContainsString('rien à traiter', $processor->process($this->id), 'error : jamais rejoué');
    }

    public function testListeDAutorisation(): void
    {
        $summary = $this->processor(['moi@example.com'])->process($this->id);

        self::assertStringContainsString('hors MAIL_ALLOWLIST', $summary);
        self::assertSame([], $this->transport->sent);
        self::assertSame('pending', $this->row()['status']);
        self::assertSame('not_allowlisted', $this->row()['pending_reason']);
        self::assertSame(0, $this->row()['attempts']);

        // Adresse autorisée (comparaison sans tenir compte de la casse) : envoyé.
        $this->processor(['client@example.com'])->process($this->id);
        self::assertSame('sent', $this->row()['status']);
        self::assertNull($this->row()['pending_reason']);
    }
}
