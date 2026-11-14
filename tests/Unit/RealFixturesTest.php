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
 * La décision attendue se déduit du nom du fichier (livraison-<cas>.<numéro>-<action>.json).
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
        // livraison-<cas>.<numéro>-<action>.json (scripts/analyse-livraisons.php --fixtures)
        self::assertMatchesRegularExpression('/^livraison-[a-z-]+\.\d+-[a-z]+(-\d+)?\.json$/', basename($file));
        $case = substr(basename($file), 10, strpos(basename($file), '.') - 10);
        $tagged = !str_ends_with($case, '-non-taguee');
        $status = (string) preg_replace('/-(non-taguee|sans-email)$/', '', $case);

        [$action, $status, $errorCode] = match (true) {
            $case === 'errors' => [Decision::RECORD_PAYLOAD_ERROR, Decision::STATUS_ERROR, Decision::ERROR_PAYLOAD],
            $case === 'annulee', $status === 'refunded' => [Decision::REVOKE, null, null],
            !in_array($status, ['paid', 'partially-refunded'], true) => [Decision::NOTHING, null, null],
            !$tagged => [Decision::RECORD, Decision::STATUS_IGNORED, null],
            str_ends_with($case, '-sans-email') => [Decision::RECORD, Decision::STATUS_ERROR, Decision::ERROR_NO_EMAIL],
            default => [Decision::RECORD, Decision::STATUS_PENDING, null],
        };

        self::assertSame($action, $decision->action, "$case : " . $decision->reason);
        self::assertSame($status, $decision->status);
        self::assertSame($errorCode, $decision->errorCode);
        self::assertNotNull($event->shopifyOrderId);
    }
}
