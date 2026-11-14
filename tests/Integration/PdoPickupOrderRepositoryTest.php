<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\OrderSnapshot;
use App\Repository\PdoPickupOrderRepository;
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
}
