<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Decision;
use App\Domain\OrderEvent;
use App\Service\OrderDecider;
use App\Service\ShopifyHmac;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Livraisons réelles anonymisées (M2), écrites par scripts/analyse-livraisons.php --fixtures.
 * La décision attendue se déduit du nom du fichier (livraison-<cas>[-n].json).
 */
final class RealFixturesTest extends TestCase
{
    public static function fixtures(): iterable
    {
        $files = glob(dirname(__DIR__) . '/fixtures/livraison-*.json') ?: [];
        if ($files === []) {
            yield 'aucune fixture' => [null];
        }
        foreach ($files as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('fixtures')]
    public function testDecisionAttendue(?string $file): void
    {
        if ($file === null) {
            self::markTestSkipped('Pas encore de fixtures réelles dans tests/fixtures/');
        }

        $fixture = json_decode((string) file_get_contents($file), true);
        $raw = $fixture['raw_body'];

        $signature = $fixture['headers']['Shopify-Hmac-Sha256'] ?? $fixture['headers']['X-Shopify-Hmac-Sha256'] ?? null;
        self::assertTrue((new ShopifyHmac('secret-de-test'))->isValid($raw, $signature), 'signature de la fixture');
        self::assertStringNotContainsString('@gmail', $raw, 'fixture non anonymisée');

        $event = OrderEvent::fromPayload(json_decode($raw, true));
        $decision = (new OrderDecider('retrait-qr'))->decide($event);
        $case = preg_replace('/^livraison-|-\d+$|\.json$/', '', basename($file));

        [$action, $status] = match (true) {
            $case === 'errors' => [Decision::RECORD_PAYLOAD_ERROR, Decision::STATUS_ERROR],
            $case === 'annulee', $case === 'refunded' => [Decision::REVOKE, null],
            str_ends_with($case, '-non-taguee') && in_array(substr($case, 0, -11), ['paid', 'partially-refunded'], true)
                => [Decision::RECORD, Decision::STATUS_IGNORED],
            str_ends_with($case, '-non-taguee') && substr($case, 0, -11) === 'refunded' => [Decision::REVOKE, null],
            in_array($case, ['paid', 'partially-refunded'], true) => [Decision::RECORD, Decision::STATUS_PENDING],
            default => [Decision::NOTHING, null],
        };

        self::assertSame($action, $decision->action, "$case : " . $decision->reason);
        self::assertSame($status, $decision->status);
        self::assertNotNull($event->shopifyOrderId);
    }
}
