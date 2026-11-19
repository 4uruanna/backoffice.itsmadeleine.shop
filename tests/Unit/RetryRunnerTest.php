<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\OrderSnapshot;
use App\Mail\QrMailBuilder;
use App\Service\AlertMailer;
use App\Service\PickupProcessor;
use App\Service\QrGenerator;
use App\Service\RetryRunner;
use App\Service\Template;
use App\Tests\Support\FakeMailBucket;
use App\Tests\Support\InMemoryPickupOrderRepository;
use App\Tests\Support\SpyAlertLog;
use App\Tests\Support\SpyMailTransport;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('gd')]
final class RetryRunnerTest extends TestCase
{
    private const ALERT_EMAIL = 'alertes@example.com';

    private InMemoryPickupOrderRepository $orders;
    private SpyMailTransport $transport;
    private FakeMailBucket $bucket;

    protected function setUp(): void
    {
        $this->orders = new InMemoryPickupOrderRepository();
        $this->transport = new SpyMailTransport();
        $this->bucket = new FakeMailBucket();
    }

    private function runner(?string $alertEmail = self::ALERT_EMAIL): RetryRunner
    {
        $processor = new PickupProcessor(
            $this->orders,
            new QrMailBuilder(new Template(dirname(__DIR__, 2) . '/templates'), new QrGenerator('https://app.itsmadeleine.shop')),
            $this->transport,
            $this->bucket,
            new SpyAlertLog(),
            3,
        );

        return new RetryRunner(
            $this->orders,
            $processor,
            new AlertMailer($this->transport, $this->bucket, $alertEmail, 'boutique'),
            null,
            3,
        );
    }

    private function add(int $n, string $status = 'pending', ?string $errorCode = null): int
    {
        $shopifyId = 8264632992000 + $n;

        return (int) $this->orders->insertIfAbsent(
            $shopifyId,
            new OrderSnapshot($shopifyId, "#10$n", "client$n@example.com", 'https://example.myshopify.com/s', 'PAID', null, ['retrait-qr']),
            $status, $errorCode, null, "wh-$n",
        );
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return (array) $this->orders->findById($id);
    }

    /** @return list<string> destinataires des mails envoyés */
    private function recipients(): array
    {
        return array_map(static fn ($m) => $m->to, $this->transport->sent);
    }

    public function testReprendLaFileDesPlusAnciennesAuxPlusRecentesEtSArreteAuPlafond(): void
    {
        $ids = [$this->add(1), $this->add(2, 'failed'), $this->add(3), $this->add(4)];
        $this->bucket->remaining = 2;

        $report = implode("\n", $this->runner()->run());

        self::assertSame(['client1@example.com', 'client2@example.com'], $this->recipients());
        self::assertSame('sent', $this->row($ids[0])['status']);
        self::assertSame('sent', $this->row($ids[1])['status']);
        self::assertSame('rate_limited', $this->row($ids[2])['pending_reason'], 'la 3e attend le passage suivant');
        self::assertSame('pending', $this->row($ids[3])['status']);
        self::assertNull($this->row($ids[3])['pending_reason'], 'arrêt au premier plafond : la 4e n\'est pas touchée');
        self::assertStringContainsString('envoyées : 2', $report);
        self::assertStringContainsString('file restante (pending et failed non révoquées) : 2', $report);

        // Passage suivant : bucket rechargé, la file se vide.
        $this->bucket->remaining = 10;
        $this->runner()->run();
        self::assertSame(['client1@example.com', 'client2@example.com', 'client3@example.com', 'client4@example.com'], $this->recipients());
    }

    public function testCommandeRevoqueeJamaisEnvoyee(): void
    {
        $id = $this->add(1);
        $this->orders->revoke(8264632992001, 'cancelled');

        $report = implode("\n", $this->runner()->run());

        self::assertSame([], $this->transport->sent);
        self::assertSame('pending', $this->row($id)['status'], 'reste en l\'état, visible dans le suivi');
        self::assertStringContainsString('traitées : 0', $report);
    }

    public function testTroisEchecsPuisUneSeuleAlerte(): void
    {
        $id = $this->add(1);
        $this->transport->failures = ['SMTP refusé', 'SMTP refusé', 'SMTP refusé'];

        $this->runner()->run();
        self::assertSame('failed', $this->row($id)['status']);
        $this->runner()->run();
        self::assertSame('failed', $this->row($id)['status']);
        self::assertSame([], $this->transport->sent, 'pas encore d\'alerte');

        $report = implode("\n", $this->runner()->run());
        self::assertSame('error', $this->row($id)['status']);
        self::assertSame('max_attempts', $this->row($id)['error_code']);
        self::assertSame([self::ALERT_EMAIL], $this->recipients(), 'une seule alerte, au passage où la commande passe en error');
        $alert = $this->transport->sent[0];
        self::assertStringContainsString('#101', $alert->text);
        self::assertStringContainsString('max_attempts', $alert->text);
        self::assertStringContainsString('SMTP refusé', $alert->text);
        self::assertStringContainsString('https://admin.shopify.com/store/boutique/orders/8264632992001', $alert->text);
        self::assertStringNotContainsString('client1@example.com', $alert->text, 'pas d\'e-mail client dans l\'alerte');
        self::assertSame('alert', $this->bucket->taken[array_key_last($this->bucket->taken)]['kind']);
        self::assertStringContainsString('alerte envoyée (1 commande)', $report);

        $this->runner()->run();
        self::assertCount(1, $this->transport->sent, 'déjà signalée : pas de nouvelle alerte');
    }

    public function testAlerteGroupeeSurPlusieursCodes(): void
    {
        $this->add(1, 'error', 'no_email');
        $this->add(2, 'error', 'payload_errors');

        $this->runner()->run();

        self::assertSame([self::ALERT_EMAIL], $this->recipients());
        self::assertStringContainsString('2 commandes', $this->transport->sent[0]->subject);
        self::assertStringContainsString('no_email', $this->transport->sent[0]->text);
        self::assertStringContainsString('payload_errors', $this->transport->sent[0]->text);
    }

    public function testAlerteCommandeIntrouvableExpliqueQuoiFaire(): void
    {
        $this->add(1, 'error', 'order_not_found');

        $this->runner()->run();

        $text = $this->transport->sent[0]->text;
        self::assertStringContainsString('order_not_found', $text);
        self::assertStringContainsString('supprimée', $text);
        self::assertStringContainsString('https://admin.shopify.com/store/boutique/orders/', $text);
    }

    public function testAlerteReporteeQuandLeBucketEstVide(): void
    {
        $this->add(1, 'error', 'no_email');
        $this->bucket->remaining = 0;

        $report = implode("\n", $this->runner()->run());
        self::assertSame([], $this->transport->sent);
        self::assertStringContainsString('alerte reportée', $report);

        $this->bucket->remaining = 5;
        $this->runner()->run();
        self::assertSame([self::ALERT_EMAIL], $this->recipients());
    }

    public function testAlerteEnEchecRetenteeAuPassageSuivant(): void
    {
        $this->add(1, 'error', 'no_email');
        $this->transport->failures = ['refus'];

        $this->runner()->run();
        self::assertSame([], $this->transport->sent);

        $this->runner()->run();
        self::assertSame([self::ALERT_EMAIL], $this->recipients());
    }

    public function testSansAlertEmailRienNEstMarque(): void
    {
        $id = $this->add(1, 'error', 'no_email');

        $report = implode("\n", $this->runner(null)->run());

        self::assertSame([], $this->transport->sent);
        self::assertNull($this->row($id)['alerted_at']);
        self::assertStringContainsString('ALERT_EMAIL non renseignée', $report);
        self::assertSame([], $this->bucket->taken, 'aucun jeton consommé');
    }

    public function testTraitementAbandonneDebloquePuisRejoue(): void
    {
        $id = $this->add(1);
        self::assertTrue($this->orders->claim($id));
        $key = 8264632992001;
        $this->orders->rows[$key]['locked_at'] = gmdate('Y-m-d H:i:s', time() - 20 * 60);

        $report = implode("\n", $this->runner()->run());

        self::assertStringContainsString('débloquées', $report);
        self::assertSame('sent', $this->row($id)['status'], 'rejouée dans le même passage');
        self::assertSame(1, $this->row($id)['attempts'], 'le blocage compte comme un essai');
    }

    public function testTraitementRecentNonDebloque(): void
    {
        $id = $this->add(1);
        self::assertTrue($this->orders->claim($id));

        $this->runner()->run();

        self::assertSame('processing', $this->row($id)['status']);
        self::assertSame([], $this->transport->sent);
    }

    public function testLigneQuiRepasseEnErreurEstDeNouveauSignalee(): void
    {
        $id = $this->add(1, 'error', 'no_email');
        $this->runner()->run();
        self::assertNotNull($this->row($id)['alerted_at']);

        // Reprise après payload_errors, puis nouvel échec définitif : alerted_at remis à NULL.
        $this->orders->rows[8264632992001] = ['status' => 'processing', 'attempts' => 2] + $this->orders->rows[8264632992001];
        $this->orders->markFailed($id, 'refus', 3);
        self::assertNull($this->row($id)['alerted_at']);
    }
}
