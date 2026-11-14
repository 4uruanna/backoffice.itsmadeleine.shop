<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Charges utiles au format relevé en M2 (commande #1094, 14/11) :
 * enveloppe topic / action / handle / data / fields_changed / query_variables.
 */
final class Payloads
{
    /** @param array<string, mixed> $order surcharges de data.order */
    public static function order(array $order = [], string $action = 'update', ?array $errors = null): array
    {
        $id = (string) ($order['legacyResourceId'] ?? '8264632992077');

        $body = [
            'topic' => 'Order',
            'action' => $action,
            'handle' => 'commande-qr-retrait',
            'data' => [
                'order' => $order + [
                    'legacyResourceId' => $id,
                    'name' => '#1094',
                    'email' => 'client@example.com',
                    'statusPageUrl' => 'https://example.myshopify.com/12345/orders/abcdef/authenticate?key=fixture',
                    'displayFinancialStatus' => 'PAID',
                    'cancelledAt' => null,
                    'lineItems' => ['nodes' => [['product' => ['tags' => ['LightStick#2027', 'retrait-qr']]]]],
                ],
            ],
            'fields_changed' => ['order.displayFinancialStatus'],
            'query_variables' => ['orderId' => 'gid://shopify/Order/' . $id],
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return $body;
    }

    /** @param list<list<string>|null> $productTags une entrée par ligne ; null = produit nul */
    public static function lines(array $productTags): array
    {
        return ['nodes' => array_map(
            static fn (?array $tags) => ['product' => $tags === null ? null : ['tags' => $tags]],
            $productTags,
        )];
    }
}
