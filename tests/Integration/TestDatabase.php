<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config;
use App\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base MySQL jetable `backoffice_test`, recréée depuis migrations/ à chaque test.
 * Variables : TEST_DB_HOST, TEST_DB_PORT, TEST_DB_USER, TEST_DB_PASSWORD
 * (définies pour le service php de docker-compose.yml). Absentes : tests ignorés.
 */
final class TestDatabase
{
    public const NAME = 'backoffice_test';

    public static function config(TestCase $test): Config
    {
        $host = getenv('TEST_DB_HOST');
        if ($host === false || $host === '') {
            $test->markTestSkipped('TEST_DB_HOST absent : tests MySQL ignorés (lancer dans Docker Compose)');
        }

        return new Config([
            'DB_HOST' => $host,
            'DB_PORT' => getenv('TEST_DB_PORT') ?: '3306',
            'DB_NAME' => self::NAME,
            'DB_USER' => getenv('TEST_DB_USER') ?: 'root',
            'DB_PASSWORD' => getenv('TEST_DB_PASSWORD') ?: '',
        ]);
    }

    public static function reset(TestCase $test): PDO
    {
        $config = self::config($test);

        $server = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config->get('DB_HOST'), $config->get('DB_PORT')),
            $config->get('DB_USER'),
            $config->get('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $server->exec('DROP DATABASE IF EXISTS ' . self::NAME);
        $server->exec('CREATE DATABASE ' . self::NAME . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $pdo = Database::connect($config);
        foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $file) {
            foreach (self::statements((string) file_get_contents($file)) as $sql) {
                $pdo->exec($sql);
            }
        }

        return $pdo;
    }

    /** Seconde connexion à la base de test (verrous tenus par une autre session). */
    public static function connect(TestCase $test): PDO
    {
        return Database::connect(self::config($test));
    }

    /** @return list<string> */
    private static function statements(string $sql): array
    {
        $sql = (string) preg_replace('/--[^\n]*/', '', $sql);

        return array_values(array_filter(array_map('trim', explode(';', $sql)), fn ($s) => $s !== ''));
    }
}
