<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Tâches à exécuter après la réponse (M7 : après fastcgi_finish_request).
 * M3 : exécutées à la fin de la requête, dans public/index.php.
 */
final class DeferredTasks
{
    /** @var list<array{string, \Closure(): void}> */
    private array $tasks = [];

    /** @param \Closure(): void $task */
    public function add(string $label, \Closure $task): void
    {
        $this->tasks[] = [$label, $task];
    }

    public function count(): int
    {
        return count($this->tasks);
    }

    /** Exécute toutes les tâches ; une exception n'empêche pas les suivantes. */
    public function run(): void
    {
        while ($this->tasks !== []) {
            [$label, $task] = array_shift($this->tasks);
            try {
                $task();
            } catch (\Throwable $e) {
                error_log(sprintf('tâche différée « %s » en échec : %s', $label, $e->getMessage()));
            }
        }
    }
}
