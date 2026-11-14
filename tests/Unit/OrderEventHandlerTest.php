<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\OrderEvent;
use App\Service\DeferredTasks;
use App\Service\OrderDecider;
use App\Service\OrderEventHandler;
use App\Tests\Support\InMemoryPickupOrderRepository;
use App\Tests\Support\Payloads;
use App\Tests\Support\SpyAlertLog;
use PHPUnit\Framework\TestCase;

final class OrderEventHandlerTest extends TestCase
{
    private const ID = 8264632992077;

    private InMemoryPickupOrderRepository $orders;
    private SpyAlertLog $alerts;
    private DeferredTasks $tasks;
    /** @var list<int> */
    private array $processed = [];
    private OrderEventHandler $handler;

    protected function setUp(): void
    {
        $this->orders = new InMemoryPickupOrderRepository();
        $this->alerts = new SpyAlertLog();
        $this->tasks = new DeferredTasks();
        $this->handler = new OrderEventHandler(
            new OrderDecider('retrait-qr'),
            $this->orders,
            $this->alerts,
            $this->tasks,
            fn (int $id) => $this->processed[] = $id,
        );
    }

    private function handle(array $body, string $webhookId = 'wh'): string
    {
        return $this->handler->handle(OrderEvent::fromPayload($body), $webhookId);
    }

    private function row(): ?array
    {
        return $this->orders->findByShopifyOrderId(self::ID);
    }

    public function testPayeeTagueeCreeUneLigneEnAttenteEtProgrammeLeTraitement(): void
    {
        $summary = $this->handle(Payloads::order(action: 'create'), 'wh-create');

        self::assertSame('pending', $this->row()['status']);
        self::assertSame('client@example.com', $this->row()['customer_email']);
        self::assertSame('wh-create', $this->row()['webhook_id']);
        self::assertStringContainsString('créée, pending', $summary);
        self::assertSame(1, $this->tasks->count());

        $this->tasks->run();
        self::assertSame([$this->row()['id']], $this->processed);
    }

    public function testDoublonCreatePuisUpdateNeCreeQuUneLigneEtUnSeulTraitement(): void
    {
        // Cas réel de M2 : deux livraisons à la même seconde pour la commande #1094.
        $this->handle(Payloads::order(action: 'create'), 'wh-1');
        $summary = $this->handle(Payloads::order(action: 'update'), 'wh-2');

        self::assertCount(1, $this->orders->rows);
        self::assertSame('wh-1', $this->row()['webhook_id']);
        self::assertStringContainsString('doublon', $summary);
        self::assertSame(1, $this->tasks->count());
    }

    public function testNonTagueeIgnoreeSansTraitementNiAlerte(): void
    {
        $this->handle(Payloads::order(['lineItems' => Payloads::lines([['autre']])]));

        self::assertSame('ignored', $this->row()['status']);
        self::assertSame(0, $this->tasks->count());
        self::assertSame([], $this->alerts->alerts);
    }

    public function testSansEmailEnErreurAvecAlerte(): void
    {
        $this->handle(Payloads::order(['email' => null]));

        self::assertSame('error', $this->row()['status']);
        self::assertSame('no_email', $this->row()['error_code']);
        self::assertSame(['no_email'], $this->alerts->codes());
        self::assertSame(0, $this->tasks->count());
    }

    public function testSansEmailRejoueNAlertePasDeuxFois(): void
    {
        $this->handle(Payloads::order(['email' => null]));
        $this->handle(Payloads::order(['email' => null]));

        self::assertSame(['no_email'], $this->alerts->codes());
    }

    public function testErrorsCreeUneLigneEnErreurAvecAlerte(): void
    {
        $body = Payloads::order(errors: [['message' => 'Access denied']]);
        $body['data']['order'] = null;

        $this->handle($body);

        self::assertSame('error', $this->row()['status']);
        self::assertSame('payload_errors', $this->row()['error_code']);
        self::assertSame('Access denied', $this->row()['last_error']);
        self::assertSame(['payload_errors'], $this->alerts->codes());
    }

    public function testErrorsNEcrasePasUneLigneValide(): void
    {
        $this->handle(Payloads::order());
        $this->handle(Payloads::order(errors: [['message' => 'x']]));

        self::assertSame('pending', $this->row()['status']);
        self::assertSame([], $this->alerts->alerts);
    }

    public function testLivraisonValideApresErrorsReprendLaCommande(): void
    {
        $broken = Payloads::order(errors: [['message' => 'x']]);
        $broken['data']['order'] = null;
        $this->handle($broken);

        $summary = $this->handle(Payloads::order(), 'wh-ok');

        self::assertSame('pending', $this->row()['status']);
        self::assertNull($this->row()['error_code']);
        self::assertNull($this->row()['last_error']);
        self::assertSame('client@example.com', $this->row()['customer_email']);
        self::assertStringContainsString('reprise', $summary);
        self::assertSame(1, $this->tasks->count());
    }

    public function testRepriseVersSansEmail(): void
    {
        $broken = Payloads::order(errors: [['message' => 'x']]);
        $broken['data']['order'] = null;
        $this->handle($broken);

        $this->handle(Payloads::order(['email' => null]));

        self::assertSame('no_email', $this->row()['error_code']);
        self::assertSame(['payload_errors', 'no_email'], $this->alerts->codes());
    }

    public function testAnnulationRevoqueLaLigneUneSeuleFois(): void
    {
        $this->handle(Payloads::order());
        $first = $this->handle(Payloads::order(['cancelledAt' => '2026-11-14T18:00:00Z']));
        $second = $this->handle(Payloads::order(['cancelledAt' => '2026-11-14T18:00:00Z']));

        self::assertNotNull($this->row()['revoked_at']);
        self::assertSame('cancelled', $this->row()['revoked_reason']);
        self::assertStringContainsString('révoquée', $first);
        self::assertStringContainsString('rien à révoquer', $second);
    }

    public function testRemboursementTotalRevoque(): void
    {
        $this->handle(Payloads::order());
        $this->handle(Payloads::order(['displayFinancialStatus' => 'REFUNDED']));

        self::assertSame('refunded', $this->row()['revoked_reason']);
    }

    public function testAnnulationSansLigneNeCreeRien(): void
    {
        $this->handle(Payloads::order(['cancelledAt' => '2026-11-14T18:00:00Z']));

        self::assertSame([], $this->orders->rows);
    }

    public function testLivraisonPayeeArriveeApresLAnnulationNeCreeRien(): void
    {
        // Livraisons dans le désordre : la requête renvoie l'état courant, déjà annulé.
        $this->handle(Payloads::order(['cancelledAt' => '2026-11-14T18:00:00Z', 'displayFinancialStatus' => 'PAID']));

        self::assertSame([], $this->orders->rows);
        self::assertSame(0, $this->tasks->count());
    }

    public function testRemboursementPartielNeRevoquePas(): void
    {
        $this->handle(Payloads::order());
        $this->handle(Payloads::order(['displayFinancialStatus' => 'PARTIALLY_REFUNDED']));

        self::assertNull($this->row()['revoked_at']);
        self::assertSame(1, $this->tasks->count());
    }

    public function testPaiementEnAttenteNeCreeRienPuisPayeeCree(): void
    {
        $this->handle(Payloads::order(['displayFinancialStatus' => 'PENDING'], action: 'create'));
        self::assertSame([], $this->orders->rows);

        $this->handle(Payloads::order(['displayFinancialStatus' => 'PAID']));
        self::assertSame('pending', $this->row()['status']);
    }
}
