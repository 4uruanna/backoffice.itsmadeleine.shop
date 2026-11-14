<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * État d'une commande tel que renvoyé par la requête de l'abonnement Events
 * (`data.order`, forme relevée en M2 sur la commande #1094).
 */
final readonly class OrderSnapshot
{
    /**
     * @param list<string> $tags tags de tous les produits des lignes (produits nuls ignorés)
     */
    public function __construct(
        public int $shopifyOrderId,
        public string $name,
        public ?string $email,
        public ?string $statusPageUrl,
        public ?string $financialStatus,
        public ?string $cancelledAt,
        public array $tags,
    ) {
    }

    /**
     * @param array<string, mixed> $order contenu de `data.order`
     */
    public static function fromOrder(array $order): ?self
    {
        $id = self::toId($order['legacyResourceId'] ?? null);
        if ($id === null) {
            return null;
        }

        $tags = [];
        $lines = $order['lineItems']['nodes'] ?? [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            // Produit nul : produit supprimé ou article personnalisé.
            $productTags = is_array($line) ? ($line['product']['tags'] ?? null) : null;
            foreach (is_array($productTags) ? $productTags : [] as $tag) {
                if (is_string($tag)) {
                    $tags[] = $tag;
                }
            }
        }

        return new self(
            shopifyOrderId: $id,
            name: self::string($order['name'] ?? null) ?? ('#' . $id),
            email: self::string($order['email'] ?? null),
            statusPageUrl: self::string($order['statusPageUrl'] ?? null),
            financialStatus: self::string($order['displayFinancialStatus'] ?? null),
            cancelledAt: self::string($order['cancelledAt'] ?? null),
            tags: $tags,
        );
    }

    /** Identifiant numérique depuis `123`, `"123"` ou `"gid://shopify/Order/123"`. */
    public static function toId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('~^(?:gid://shopify/Order/)?([1-9][0-9]{0,18})$~', $value, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    public function hasTag(string $wanted): bool
    {
        $wanted = trim($wanted);
        foreach ($this->tags as $tag) {
            if (strcasecmp(trim($tag), $wanted) === 0) {
                return true;
            }
        }

        return false;
    }

    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
