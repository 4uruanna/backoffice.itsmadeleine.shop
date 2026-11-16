<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Purge du cron (M7) : commandes (et leurs scans, par cascade) après RETENTION_MONTHS,
 * `mail_log` après MAIL_LOG_RETENTION_DAYS, fichiers de var/log/ après $logDays jours.
 * Une durée nulle ou négative désactive la purge concernée (une faute dans .env ne vide
 * jamais la base).
 */
final readonly class Purger
{
    /** Fichiers jamais supprimés (protection des dossiers). */
    private const KEEP = ['.htaccess', '.gitkeep', '.gitignore'];

    /** @param \Closure(): PDO $connect */
    public function __construct(
        private \Closure $connect,
        private string $logDir,
        private int $retentionMonths,
        private int $mailLogDays,
        private int $logDays = 30,
    ) {
    }

    /** @return array{orders: int, mail_log: int, files: int} */
    public function purge(): array
    {
        $pdo = ($this->connect)();
        $orders = 0;
        $mailLog = 0;

        if ($this->retentionMonths > 0) {
            // Les scans suivent par ON DELETE CASCADE.
            $statement = $pdo->prepare('DELETE FROM pickup_orders WHERE created_at < UTC_TIMESTAMP() - INTERVAL ? MONTH');
            $statement->execute([$this->retentionMonths]);
            $orders = $statement->rowCount();
        }

        if ($this->mailLogDays > 0) {
            $statement = $pdo->prepare('DELETE FROM mail_log WHERE sent_at < UTC_TIMESTAMP() - INTERVAL ? DAY');
            $statement->execute([$this->mailLogDays]);
            $mailLog = $statement->rowCount();
        }

        return ['orders' => $orders, 'mail_log' => $mailLog, 'files' => $this->purgeFiles()];
    }

    private function purgeFiles(): int
    {
        if ($this->logDays <= 0 || !is_dir($this->logDir)) {
            return 0;
        }

        $limit = time() - $this->logDays * 86400;
        $count = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->logDir, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isFile() && !$file->isLink() && !in_array($file->getFilename(), self::KEEP, true)
                && $file->getMTime() < $limit && @unlink($file->getPathname())) {
                $count++;
            }
        }

        return $count;
    }
}
