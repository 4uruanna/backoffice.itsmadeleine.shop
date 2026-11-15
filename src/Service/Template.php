<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Gabarits PHP de templates/ (pages de scan en M4, mail en M5).
 * Les variables sont extraites dans la portée du gabarit ; `$e` échappe pour le HTML.
 */
final readonly class Template
{
    public function __construct(private string $directory)
    {
    }

    /** @param array<string, mixed> $variables */
    public function render(string $name, array $variables = []): string
    {
        $path = $this->directory . '/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Gabarit introuvable : %s', $name));
        }

        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $render = static function (string $__path, array $__variables) use ($e): string {
            extract($__variables, EXTR_SKIP);
            ob_start();
            try {
                require $__path;

                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };

        return $render($path, $variables);
    }
}
