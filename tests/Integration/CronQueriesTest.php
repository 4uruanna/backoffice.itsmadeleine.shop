<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\OrderSnapshot;
use App\Repository\PdoPickupOrderRepository;
use App\Service\Purger;
use PDO;
use PHPUnit\Framework\TestCase;

/** Requêtes du cron (M7) sur MySQL : file, déblocage, alertes, purge. */
final class CronQueriesTest extends TestCase
{
    private PDO $pdo;
    private PdoPickupOrderRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset($this);
        $this->repository = new PdoPickupOrderRepository(fn () => $this->pdo);
    }

    private function add(int $n, string $status = 'pending', ?string $errorCode = null, string $createdAt = '-1 HOUR'): int
    {
        $shopifyId = 8264632992000 + $n;
        $id = (int) $this->repository->insertIfAbsent(
            $shopifyId,
            new OrderSnapshot($shopifyId, "#10$n", "client$n@example.com", null, 'PAID', null, ['retrait-qr']),
            $status, $errorCode, null, null,
        );
        $this->pdo->exec("UPDATE pickup_orders SET created_at = UTC_TIMESTAMP() + INTERVAL $createdAt WHERE id = $id");

        return $id;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return (array) $this->repository->findById($id);
    }

    public function testFileDesPlusAnciennesAuxPlusRecentesSansRevoqueesNiAutresStatuts(): void
    {
        $recent = $this->add(1, createdAt: '-1 MINUTE');
        $oldest = $this->add(2, 'failed', createdAt: '-3 HOUR');
        $middle = $this->add(3, createdAt: '-2 HOUR');
        $revoked = $this->add(4, createdAt: '-4 HOUR');
        $this->repository->revoke(8264632992004, 'cancelled');
        $this->add(5, 'error', 'no_email', '-5 HOUR');
        $this->add(6, 'ignored', createdAt: '-6 HOUR');

        self::assertSame([$oldest, $middle, $recent], $this->repository->findRetryable(10));
        self::assertSame([$oldest], $this->repository->findRetryable(1));
        self::assertSame(3, $this->repository->countRetryable());
        self::assertNotContains($revoked, $this->repository->findRetryable(10));
    }

    public function testDeblocageDesTraitementsAbandonnes(): void
    {
        $stale = $this->add(1);
        $lastTry = $this->add(2);
        $fresh = $this->add(3);
        foreach ([$stale, $lastTry, $fresh] as $id) {
            self::assertTrue($this->repository->claim($id));
        }
        $this->pdo->exec("UPDATE pickup_orders SET locked_at = UTC_TIMESTAMP() - INTERVAL 20 MINUTE WHERE id IN ($stale, $lastTry)");
        $this->pdo->exec("UPDATE pickup_orders SET attempts = 2, alerted_at = UTC_TIMESTAMP() WHERE id = $lastTry");

        self::assertSame(2, $this->repository->unlockStale(15, 3));

        self::assertSame('failed', $this->row($stale)['status']);
        self::assertSame('stale_lock', $this->row($stale)['last_error']);
        self::assertSame(1, (int) $this->row($stale)['attempts']);
        self::assertNull($this->row($stale)['locked_at']);

        self::assertSame('error', $this->row($lastTry)['status']);
        self::assertSame('max_attempts', $this->row($lastTry)['error_code']);
        self::assertNull($this->row($lastTry)['alerted_at'], 'à signaler');

        self::assertSame('processing', $this->row($fresh)['status']);
    }

    public function testAlertesNonSignaleesPuisMarquees(): void
    {
        $first = $this->add(1, 'error', 'no_email', '-2 HOUR');
        $second = $this->add(2, 'error', 'payload_errors', '-1 HOUR');
        $this->add(3);

        $errors = $this->repository->findUnalertedErrors(10);
        self::assertSame([$first, $second], array_map(static fn ($r) => (int) $r['id'], $errors));
        self::assertSame('#101', $errors[0]['order_name']);
        self::assertArrayNotHasKey('customer_email', $errors[0], 'pas d\'e-mail client dans l\'alerte');

        $this->repository->markAlerted([$first, $second]);
        self::assertSame([], $this->repository->findUnalertedErrors(10));
        $this->repository->markAlerted([]);
    }

    public function testEchecDefinitifRemetLaLigneASignaler(): void
    {
        $id = $this->add(1);
        $this->pdo->exec("UPDATE pickup_orders SET attempts = 2, alerted_at = UTC_TIMESTAMP() WHERE id = $id");
        self::assertTrue($this->repository->claim($id));

        self::assertSame('error', $this->repository->markFailed($id, 'refus', 3));
        self::assertNull($this->row($id)['alerted_at']);
        self::assertSame(3, (int) $this->row($id)['attempts']);
        self::assertSame('max_attempts', $this->row($id)['error_code']);
    }

    public function testEchecNonDefinitifConserveAlertedAt(): void
    {
        $id = $this->add(1);
        $this->pdo->exec("UPDATE pickup_orders SET alerted_at = '2026-11-01 00:00:00' WHERE id = $id");
        self::assertTrue($this->repository->claim($id));

        self::assertSame('failed', $this->repository->markFailed($id, 'refus', 3));
        self::assertSame('2026-11-01 00:00:00', $this->row($id)['alerted_at']);
        self::assertNull($this->row($id)['error_code']);
    }

    public function testRepriseApresPayloadErrorsRemetLaLigneASignaler(): void
    {
        $id = $this->add(1, 'error', 'payload_errors');
        $this->repository->markAlerted([$id]);

        $order = new OrderSnapshot(8264632992001, '#101', 'client1@example.com', null, 'PAID', null, ['retrait-qr']);
        self::assertSame($id, $this->repository->resumeFromPayloadError($order, 'pending', null, 'wh'));
        self::assertNull($this->row($id)['alerted_at']);
    }

    public function testPurge(): void
    {
        $old = $this->add(1, 'sent', createdAt: '-13 MONTH');
        $kept = $this->add(2, 'sent', createdAt: '-11 MONTH');
        $this->pdo->exec("INSERT INTO scans (pickup_order_id, result, scanned_at) VALUES ($old, 'redirected', UTC_TIMESTAMP()), ($kept, 'redirected', UTC_TIMESTAMP())");
        $this->pdo->exec("INSERT INTO mail_log (pickup_order_id, kind, sent_at) VALUES
            ($old, 'qr', UTC_TIMESTAMP() - INTERVAL 31 DAY), (NULL, 'alert', UTC_TIMESTAMP() - INTERVAL 29 DAY)");

        $dir = sys_get_temp_dir() . '/purge-' . bin2hex(random_bytes(4));
        mkdir($dir . '/deliveries', 0777, true);
        foreach (['alerts.log' => 0, 'deliveries/old.json' => 31, 'deliveries/recent.json' => 29, '.htaccess' => 90] as $file => $days) {
            file_put_contents("$dir/$file", 'x');
            touch("$dir/$file", time() - $days * 86400);
        }

        $purged = (new Purger(fn () => $this->pdo, $dir, 12, 30))->purge();

        self::assertSame(['orders' => 1, 'mail_log' => 1, 'files' => 1], $purged);
        self::assertNull($this->repository->findById($old));
        self::assertNotNull($this->repository->findById($kept));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn(), 'scans supprimés par cascade');
        self::assertFileDoesNotExist("$dir/deliveries/old.json");
        self::assertFileExists("$dir/deliveries/recent.json");
        self::assertFileExists("$dir/alerts.log");
        self::assertFileExists("$dir/.htaccess");

        array_map('unlink', array_filter((array) glob("$dir/{,.}*", GLOB_BRACE), 'is_file'));
        array_map('unlink', (array) glob("$dir/deliveries/*"));
        rmdir("$dir/deliveries");
        rmdir($dir);
    }

    public function testPurgeDesactiveeParUneDureeNulle(): void
    {
        $this->add(1, 'sent', createdAt: '-13 MONTH');

        $purged = (new Purger(fn () => $this->pdo, '/nonexistent', 0, 0))->purge();

        self::assertSame(['orders' => 0, 'mail_log' => 0, 'files' => 0], $purged);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pickup_orders')->fetchColumn());
    }
}
