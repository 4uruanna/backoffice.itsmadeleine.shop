<?php

declare(strict_types=1);

namespace App;

use Dotenv\Dotenv;

/**
 * Configuration lue dans `.env` (à la racine du projet, hors racine web)
 * ou dans les variables d'environnement.
 */
final class Config
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function fromEnvironment(string $projectRoot): self
    {
        // safeLoad : pas d'erreur si .env est absent (variables fournies par Docker, tests).
        Dotenv::createImmutable($projectRoot)->safeLoad();

        $values = [];
        foreach ($_ENV + getenv() as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * @throws \RuntimeException si la clé est absente ou vide
     */
    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new \RuntimeException(sprintf('Configuration manquante : %s', $key));
        }

        return $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }
}
