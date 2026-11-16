<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\OrderSnapshot;
use App\Repository\PdoPickupOrderRepository;
use App\Repository\PdoScanRepository;
use App\Repository\ScanRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoPickupOrderRepositoryTest extends TestCase
{
    private const ID = 8264632992077;

    private PDO $pdo;
    private PdoPickupOrderRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset($this);
        $this->repository = new PdoPickupOrderRepository(fn () => $this->pdo);
    }

    private function order(array $overrides = []): OrderSnapshot
    {
        $v = $overrides + [
            'id' => self::ID, 'name' => '#1094', 'email' => 'client@example.com',
            'url' => 'https://example.myshopify.com/s', 'status' => 'PAID',
        ];

        return new OrderSnapshot($v['id'], $v['name'], $v['email'], $v['url'], $v['status'], null, ['retrait-qr']);
    }

    public function testInsertionPuisDoublon(): void
    {
        $id = $this->repository->insertIfAbsent(self::ID, $this->order(), 'pending', null, null, 'wh-1');
        self::assertIsInt($id);

        self::assertNull($this->repository->insertIfAbsent(self::ID, $this->order(), 'pending', null, null, 'wh-2'));

        $row = $this->repository->findByShopifyOrderId(self::ID);
        self::assertSame($id, (int) $row['id']);
        self::assertSame('wh-1', $row['webhook_id']);
        self::assertSame('pending', $row['status']);
        self::assertSame('client@example.com', $row['customer_email']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pickup_orders')->fetchColumn());
    }

    public function testDatesEnUtc(): void
    {
        $this->repository->insertIfAbsent(self::ID, $this->order(), 'pending', null, null, null);

        $created = $this->repository->findByShopifyOrderId(self::ID)['created_at'];
        $drift = abs(strtotime($created . ' UTC') - time());
        self::assertLessThan(5, $drift, "created_at ($created) doit être en UTC");
    }

    public function testValeursTropLonguesTronqueesSansErreur(): void
    {
        $order = $this->order(['name' => str_repeat('#', 40), 'url' => 'https://x/' . str_repeat('a', 600)]);

        $this->repository->insertIfAbsent(self::ID, $order, 'pending', null, str_repeat('e', 600), str_repeat('w', 80));

        $row = $this->repository->findByShopifyOrderId(self::ID);
        self::assertSame(32, strlen($row['order_name']));
        self::assertSame(500, strlen($row['status_page_url']));
        self::assertSame(64, strlen($row['webhook_id']));
    }

    public function testStatutInvalideLeveUneErreur(): void
    {
        // Contrairement à INSERT IGNORE, une valeur invalide n'est pas avalée en silence.
        $this->expectException(\PDOException::class);
        $this->repository->insertIfAbsent(self::ID, $this->order(), 'inconnu', null, null, null);
    }

    public function testRevocationIdempotente(): void
    {
        self::assertFalse($this->repository->revoke(self::ID, 'cancelled'), 'aucune ligne');

        $this->repository->insertIfAbsent(self::ID, $this->order(), 'pending', null, null, null);
        self::assertTrue($this->repository->revoke(self::ID, 'cancelled'));
        self::assertFalse($this->repository->revoke(self::ID, 'refunded'), 'déjà révoquée');

        $row = $this->repository->findByShopifyOrderId(self::ID);
        self::assertSame('cancelled', $row['revoked_reason']);
        self::assertNotNull($row['revoked_at']);
    }

    public function testRepriseUniquementDepuisPayloadErrors(): void
    {
        $this->repository->insertIfAbsent(self::ID, null, 'error', 'payload_errors', 'Access denied', 'wh-1');
        $this->pdo->exec('UPDATE pickup_orders SET attempts = 2');

        $id = $this->repository->resumeFromPayloadError($this->order(), 'pending', null, 'wh-2');

        self::assertIsInt($id);
        $row = $this->repository->findByShopifyOrderId(self::ID);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['error_code']);
        self::assertNull($row['last_error']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertSame('#1094', $row['order_name']);
        self::assertSame('wh-2', $row['webhook_id']);

        self::assertNull($this->repository->resumeFromPayloadError($this->order(), 'pending', null, 'wh-3'), 'plus en erreur');
    }

    public function testPasDeRepriseDUneErreurNoEmail(): void
    {
        $this->repository->insertIfAbsent(self::ID, $this->order(['email' => null]), 'error', 'no_email', null, null);

        self::assertNull($this->repository->resumeFromPayloadError($this->order(), 'pending', null, null));
    }

    public function testLivraisonsSimultaneesUneSeuleInsertion(): void
    {
        // Comme create + update de la commande #1094 (M2), mais avec 8 processus en parallèle.
        $workers = 8;
        $startAt = microtime(true) + 1.0;
        $script = __DIR__ . '/insert-worker.php';
        $processes = [];
        for ($i = 0; $i < $workers; $i++) {
            $processes[] = proc_open(
                [PHP_BINARY, $script, (string) $startAt, (string) self::ID, (string) $i],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
            );
        }

        $results = [];
        foreach ($processes as $i => $process) {
            $results[] = trim((string) stream_get_contents($pipes[$i][1]));
            $errors = trim((string) stream_get_contents($pipes[$i][2]));
            self::assertSame(0, proc_close($process), $errors);
        }

        $inserts = array_filter($results, fn ($r) => str_starts_with($r, 'insert:'));
        self::assertCount(1, $inserts, implode(', ', $results));
        self::assertCount($workers - 1, array_keys($results, 'doublon', true));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pickup_orders')->fetchColumn());
    }

    public function testJetonAttribueUneSeuleFois(): void
    {
        $id = (int) $this->repository->insertIfAbsent(self::ID, $this->order(), 'pending', null, null, null);
        $first = str_repeat('a', 32);

        self::assertSame($first, $this->repository->assignToken($id, $first));
        self::assertSame($first, $this->repository->assignToken($id, str_repeat('b', 32)), 'jeton existant conservé');
        self::assertNull($this->repository->assignToken($id + 1, str_repeat('c', 32)), 'ligne absente');

        self::assertSame($id, (int) $this->repository->findByToken($first)['id']);
        self::assertNull($this->repository->findByToken(str_repeat('b', 32)));
    }

    public function testScansJournalisesEtSupprimesAvecLaCommande(): void
    {
        $id = (int) $this->repository->insertIfAbsent(self::ID, $this->order(), 'pending', null, null, null);
        $scans = new PdoScanRepository(fn () => $this->pdo);

        $scans->record($id, ScanRepository::REDIRECTED, '2001:db8::1', str_repeat('u', 300));
        $scans->record($id, ScanRepository::REVOKED, null, null);

        $rows = $this->pdo->query('SELECT * FROM scans ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['redirected', 'revoked'], array_column($rows, 'result'));
        self::assertSame('2001:db8::1', $rows[0]['ip']);
        self::assertSame(255, strlen($rows[0]['user_agent']));
        self::assertLessThan(5, abs(strtotime($rows[0]['scanned_at'] . ' UTC') - time()), 'scanned_at en UTC');

        // Purge (M7) : les scans suivent la commande (ON DELETE CASCADE).
        $this->pdo->exec('DELETE FROM pickup_orders');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn());
    }

    public function testResultatDeScanInvalideLeveUneErreur(): void
    {
        $id = (int) $this->repository->insertIfAbsent(self::ID, $this->order(), 'pending', null, null, null);

        $this->expectException(\PDOException::class);
        (new PdoScanRepository(fn () => $this->pdo))->record($id, 'inconnu', null, null);
    }

    public function testPriseEnChargeAtomiqueEtEnvoi(): void
    {
        $id = (int) $this->repository->insertIfAbsent(self::ID, $this->order(), 'pending', null, null, null);

        self::assertTrue($this->repository->claim($id));
        self::assertFalse($this->repository->claim($id), 'déjà prise');
        $row = $this->repository->findById($id);
        self::assertSame('processing', $row['status']);
        self::assertNotNull($row['locked_at']);

        $this->repository->markSent($id);
        $row = $this->repository->findById($id);
        self::assertSame('sent', $row['status']);
        self::assertNull($row['locked_at']);
        self::assertLessThan(5, abs(strtotime($row['sent_at'] . ' UTC') - time()), 'sent_at en UTC');
        self::assertFalse($this->repository->claim($id), 'envoyée : jamais reprise');
    }

    public function testRevoqueeJamaisPriseEnCharge(): void
    {
        $id = (int) $this->repository->insertIfAbsent(self::ID, $this->order(), 'pending', null, null, null);
        $this->repository->revoke(self::ID, 'cancelled');

        self::assertFalse($this->repository->claim($id));
    }

    public function testEchecsSuccessifsPuisErreur(): void
    {
        $id = (int) $this->repository->insertIfAbsent(self::ID, $this->order(), 'pending', null, null, null);

        foreach ([1 => 'failed', 2 => 'failed', 3 => 'error'] as $attempt => $expected) {
            self::assertTrue($this->repository->claim($id), "essai $attempt");
            self::assertSame($expected, $this->repository->markFailed($id, str_repeat('x', 600), 3));
            $row = $this->repository->findById($id);
            self::assertSame($attempt, (int) $row['attempts']);
            self::assertSame($expected === 'error' ? 'max_attempts' : null, $row['error_code']);
            self::assertSame(500, strlen($row['last_error']));
            self::assertNull($row['locked_at']);
        }

        self::assertFalse($this->repository->claim($id), 'error : jamais rejouée');
    }

    public function testLiberationSansTentative(): void
    {
        $id = (int) $this->repository->insertIfAbsent(self::ID, $this->order(), 'pending', null, null, null);
        $this->repository->claim($id);

        $this->repository->release($id, 'not_allowlisted');

        $row = $this->repository->findById($id);
        self::assertSame('pending', $row['status']);
        self::assertSame('not_allowlisted', $row['pending_reason']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertNull($row['locked_at']);

        self::assertTrue($this->repository->claim($id), 'reprise possible ensuite');
        $this->repository->markSent($id);
        self::assertNull($this->repository->findById($id)['pending_reason']);
    }
}
