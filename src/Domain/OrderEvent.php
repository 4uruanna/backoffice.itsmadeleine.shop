<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Livraison Events décodée : enveloppe `topic`, `action`, `handle`, `data`,
 * `fields_changed`, `query_variables` (+ `errors` éventuel).
 */
final readonly class OrderEvent
{
    public function __construct(
        /** Commande exploitable, ou null si `data.order` est absent ou inutilisable. */
        public ?OrderSnapshot $order,
        /** Identifiant de commande, même quand `data.order` est inutilisable (tiré de `query_variables`). */
        public ?int $shopifyOrderId,
        public bool $hasErrors,
        public ?string $errorSummary,
        public ?string $action,
    ) {
    }

    /** @param array<mixed> $body */
    public static function fromPayload(array $body): self
    {
        $orderData = $body['data']['order'] ?? null;
        $order = is_array($orderData) ? OrderSnapshot::fromOrder($orderData) : null;

        $errors = $body['errors'] ?? null;
        $hasErrors = $errors !== null && $errors !== [] && $errors !== '';

        $id = $order?->shopifyOrderId;
        if ($id === null) {
            foreach (is_array($body['query_variables'] ?? null) ? $body['query_variables'] : [] as $value) {
                $id = OrderSnapshot::toId($value);
                if ($id !== null) {
                    break;
                }
            }
        }

        return new self(
            order: $order,
            shopifyOrderId: $id,
            hasErrors: $hasErrors,
            errorSummary: $hasErrors ? self::summarize($errors) : null,
            action: is_string($body['action'] ?? null) ? $body['action'] : null,
        );
    }

    private static function summarize(mixed $errors): string
    {
        $messages = [];
        foreach (is_array($errors) ? $errors : [$errors] as $error) {
            $messages[] = is_array($error) && is_string($error['message'] ?? null)
                ? $error['message']
                : (string) json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return mb_substr(implode(' | ', $messages), 0, 500);
    }
}
