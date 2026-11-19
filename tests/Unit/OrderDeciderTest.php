<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Decision;
use App\Domain\OrderEvent;
use App\Service\OrderDecider;
use App\Tests\Support\Payloads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Une ligne de la table de décision de M3 par cas. */
final class OrderDeciderTest extends TestCase
{
    private function decide(array $body): Decision
    {
        return (new OrderDecider('retrait-qr'))->decide(OrderEvent::fromPayload($body));
    }

    public function testErrorsAvecIdentifiantEnregistreUneErreurDeChargeUtile(): void
    {
        $body = Payloads::order(errors: [['message' => 'Access denied']]);
        $body['data']['order'] = null;

        $decision = $this->decide($body);

        self::assertSame(Decision::RECORD_PAYLOAD_ERROR, $decision->action);
        self::assertSame(Decision::ERROR_PAYLOAD, $decision->errorCode);
        self::assertSame('Access denied', $decision->reason);
    }

    public function testErrorsAvecCommandeLisiblePrimeQuandMeme(): void
    {
        self::assertSame(Decision::RECORD_PAYLOAD_ERROR, $this->decide(Payloads::order(errors: [['message' => 'x']]))->action);
    }

    public function testErrorsSansIdentifiantNeFaitRien(): void
    {
        $body = ['errors' => [['message' => 'x']], 'data' => ['order' => null]];

        self::assertSame(Decision::NOTHING, $this->decide($body)->action);
    }

    public function testCommandeIllisibleSansErrorsEstUneErreurDeChargeUtile(): void
    {
        $body = Payloads::order();
        $body['data']['order'] = ['name' => '#1'];

        $decision = $this->decide($body);

        self::assertSame(Decision::RECORD_PAYLOAD_ERROR, $decision->action);
        self::assertSame(Decision::ERROR_PAYLOAD, $decision->errorCode);
        self::assertSame('data.order inutilisable', $decision->reason);
    }

    public function testCommandeNulleSansErrorsEstUneCommandeIntrouvable(): void
    {
        // Cas du 19/11 : commande supprimée dans Shopify, la requête de l'abonnement renvoie order: null.
        $body = Payloads::order(action: 'update');
        $body['data']['order'] = null;

        $decision = $this->decide($body);

        self::assertSame(Decision::RECORD_PAYLOAD_ERROR, $decision->action);
        self::assertSame(Decision::STATUS_ERROR, $decision->status);
        self::assertSame(Decision::ERROR_ORDER_NOT_FOUND, $decision->errorCode);
        self::assertStringContainsString('introuvable', $decision->reason);
        self::assertStringContainsString('update', $decision->reason);
    }

    public function testCommandeNulleSansIdentifiantNeFaitRien(): void
    {
        $body = Payloads::order();
        $body['data']['order'] = null;
        unset($body['query_variables']);

        self::assertSame(Decision::NOTHING, $this->decide($body)->action);
    }

    public function testAnnuleeRevoquee(): void
    {
        $decision = $this->decide(Payloads::order(['cancelledAt' => '2026-11-14T18:00:00Z']));

        self::assertSame(Decision::REVOKE, $decision->action);
        self::assertSame('cancelled', $decision->revokedReason);
    }

    public function testAnnuleeEtRembourseeRevoqueePourAnnulation(): void
    {
        $decision = $this->decide(Payloads::order(['cancelledAt' => '2026-11-14T18:00:00Z', 'displayFinancialStatus' => 'REFUNDED']));

        self::assertSame('cancelled', $decision->revokedReason);
    }

    public function testRembourseeEnTotaliteRevoquee(): void
    {
        $decision = $this->decide(Payloads::order(['displayFinancialStatus' => 'REFUNDED']));

        self::assertSame(Decision::REVOKE, $decision->action);
        self::assertSame('refunded', $decision->revokedReason);
    }

    public function testRembourseeSansTagEstAussiRevoquee(): void
    {
        // La révocation ne touche que les lignes existantes : sans tag, il n'y en a pas.
        $decision = $this->decide(Payloads::order(['displayFinancialStatus' => 'REFUNDED', 'lineItems' => Payloads::lines([['autre']])]));

        self::assertSame(Decision::REVOKE, $decision->action);
    }

    #[DataProvider('statutsPayes')]
    public function testPayeeTagueeAvecEmailEnAttente(string $status): void
    {
        $decision = $this->decide(Payloads::order(['displayFinancialStatus' => $status]));

        self::assertSame(Decision::RECORD, $decision->action);
        self::assertSame(Decision::STATUS_PENDING, $decision->status);
        self::assertNull($decision->errorCode);
    }

    public static function statutsPayes(): iterable
    {
        yield 'PAID' => ['PAID'];
        yield 'PARTIALLY_REFUNDED' => ['PARTIALLY_REFUNDED'];
    }

    public function testPayeeNonTagueeIgnoree(): void
    {
        $decision = $this->decide(Payloads::order(['lineItems' => Payloads::lines([['LightStick#2027'], null])]));

        self::assertSame(Decision::RECORD, $decision->action);
        self::assertSame(Decision::STATUS_IGNORED, $decision->status);
    }

    public function testPayeeTagueeSansEmailEnErreur(): void
    {
        $decision = $this->decide(Payloads::order(['email' => null]));

        self::assertSame(Decision::RECORD, $decision->action);
        self::assertSame(Decision::STATUS_ERROR, $decision->status);
        self::assertSame(Decision::ERROR_NO_EMAIL, $decision->errorCode);
    }

    #[DataProvider('autresStatuts')]
    public function testAutresStatutsSansEffet(?string $status): void
    {
        self::assertSame(Decision::NOTHING, $this->decide(Payloads::order(['displayFinancialStatus' => $status]))->action);
    }

    public static function autresStatuts(): iterable
    {
        foreach (['PENDING', 'AUTHORIZED', 'PARTIALLY_PAID', 'VOIDED', 'EXPIRED', null] as $status) {
            yield (string) ($status ?? 'null') => [$status];
        }
    }

    public function testTagConfigurable(): void
    {
        $decider = new OrderDecider('click-collect');
        $event = OrderEvent::fromPayload(Payloads::order(['lineItems' => Payloads::lines([['Click-Collect']])]));

        self::assertSame(Decision::STATUS_PENDING, $decider->decide($event)->status);
    }
}
