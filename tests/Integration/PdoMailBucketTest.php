<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Mail\PdoMailBucket;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoMailBucketTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset($this);
    }

    private function bucket(int $max): PdoMailBucket
    {
        return new PdoMailBucket(fn () => $this->pdo, $max);
    }

    private function logCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM mail_log')->fetchColumn();
    }

    public function testPlafondADeux(): void
    {
        $bucket = $this->bucket(2);

        self::assertTrue($bucket->tryAcquire(null, 'qr'));
        self::assertTrue($bucket->tryAcquire(null, 'alert'), 'alertes comptées avec les mails clients');
        self::assertFalse($bucket->tryAcquire(null, 'qr'));
        self::assertSame(2, $this->logCount(), 'refus : rien d\'inséré');
        self::assertSame(['qr', 'alert'], $this->pdo->query('SELECT kind FROM mail_log ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testFenetreGlissanteDeSoixanteMinutes(): void
    {
        $this->pdo->exec("INSERT INTO mail_log (pickup_order_id, kind, sent_at) VALUES
            (NULL, 'qr', UTC_TIMESTAMP() - INTERVAL 61 MINUTE),
            (NULL, 'qr', UTC_TIMESTAMP() - INTERVAL 59 MINUTE)");
        $bucket = $this->bucket(2);

        self::assertTrue($bucket->tryAcquire(null, 'qr'), 'la ligne de 61 min ne compte plus');
        self::assertFalse($bucket->tryAcquire(null, 'qr'), 'celle de 59 min compte encore');
    }

    public function testDatesEnUtc(): void
    {
        $this->bucket(1)->tryAcquire(null, 'qr');

        $sentAt = (string) $this->pdo->query('SELECT sent_at FROM mail_log')->fetchColumn();
        self::assertLessThan(5, abs(strtotime($sentAt . ' UTC') - time()));
    }

    public function testPlafondNulOuNegatifRienNePart(): void
    {
        self::assertFalse($this->bucket(0)->tryAcquire(null, 'qr'));
        self::assertSame(0, $this->logCount());
    }

    public function testVerrouLibereApresUsage(): void
    {
        $this->bucket(1)->tryAcquire(null, 'qr');
        $this->bucket(1)->tryAcquire(null, 'qr');

        self::assertSame(1, (int) $this->pdo->query("SELECT IS_FREE_LOCK('mail_bucket')")->fetchColumn());
    }

    public function testVerrouIndisponibleTraiteCommeBucketVide(): void
    {
        $other = TestDatabase::connect($this);
        self::assertSame(1, (int) $other->query("SELECT GET_LOCK('mail_bucket', 0)")->fetchColumn());
        $previous = ini_set('error_log', '/dev/null');

        try {
            $result = (new PdoMailBucket(fn () => $this->pdo, 10, lockTimeoutSeconds: 0))->tryAcquire(null, 'qr');
        } finally {
            ini_set('error_log', (string) $previous);
            $other->query("SELECT RELEASE_LOCK('mail_bucket')");
        }

        self::assertFalse($result);
        self::assertSame(0, $this->logCount());
    }

    public function testAccesSimultanesPlafondRespecte(): void
    {
        $workers = 8;
        $max = 3;
        $startAt = microtime(true) + 1.0;
        $script = __DIR__ . '/bucket-worker.php';

        $processes = [];
        $pipes = [];
        for ($i = 0; $i < $workers; $i++) {
            $processes[] = proc_open(
                [PHP_BINARY, $script, (string) $startAt, (string) $max],
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

        self::assertCount($max, array_keys($results, 'ok', true), implode(', ', $results));
        self::assertCount($workers - $max, array_keys($results, 'vide', true));
        self::assertSame($max, $this->logCount());
    }
}
