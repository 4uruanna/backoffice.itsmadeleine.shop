<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\OrderEvent;
use App\Domain\OrderSnapshot;
use App\Tests\Support\Payloads;
use PHPUnit\Framework\TestCase;

final class OrderEventTest extends TestCase
{
    public function testLitLaFormeReelleDeLaLivraison(): void
    {
        $event = OrderEvent::fromPayload(Payloads::order(action: 'create'));

        self::assertFalse($event->hasErrors);
        self::assertSame('create', $event->action);
        self::assertSame(8264632992077, $event->shopifyOrderId);
        $order = $event->order;
        self::assertNotNull($order);
        self::assertSame('#1094', $order->name);
        self::assertSame('client@example.com', $order->email);
        self::assertSame('PAID', $order->financialStatus);
        self::assertNull($order->cancelledAt);
        self::assertSame(['LightStick#2027', 'retrait-qr'], $order->tags);
        self::assertTrue($order->hasTag('retrait-qr'));
    }

    public function testTagSansTenirCompteDeLaCasseNiDesEspacesEtProduitNulIgnore(): void
    {
        $event = OrderEvent::fromPayload(Payloads::order([
            'lineItems' => Payloads::lines([null, ['Gâteau'], ['  Retrait-QR ']]),
        ]));

        self::assertSame(['Gâteau', '  Retrait-QR '], $event->order?->tags);
        self::assertTrue($event->order?->hasTag('retrait-qr'));
        self::assertFalse($event->order?->hasTag('retrait'));
    }

    public function testEmailVideConsidereCommeAbsent(): void
    {
        self::assertNull(OrderEvent::fromPayload(Payloads::order(['email' => '  ']))->order?->email);
        self::assertNull(OrderEvent::fromPayload(Payloads::order(['email' => null]))->order?->email);
    }

    public function testErrorsEtIdentifiantTireDesQueryVariables(): void
    {
        $body = Payloads::order(errors: [['message' => 'Access denied for email field'], ['message' => 'autre']]);
        $body['data']['order'] = null;

        $event = OrderEvent::fromPayload($body);

        self::assertTrue($event->hasErrors);
        self::assertNull($event->order);
        self::assertSame(8264632992077, $event->shopifyOrderId);
        self::assertSame('Access denied for email field | autre', $event->errorSummary);
    }

    public function testErrorsVidesIgnores(): void
    {
        self::assertFalse(OrderEvent::fromPayload(Payloads::order(errors: []))->hasErrors);
    }

    public function testIdentifiants(): void
    {
        self::assertSame(123, OrderSnapshot::toId('123'));
        self::assertSame(123, OrderSnapshot::toId(123));
        self::assertSame(123, OrderSnapshot::toId('gid://shopify/Order/123'));
        self::assertNull(OrderSnapshot::toId('gid://shopify/Customer/123'));
        self::assertNull(OrderSnapshot::toId('0'));
        self::assertNull(OrderSnapshot::toId('12a'));
        self::assertNull(OrderSnapshot::toId(null));
    }
}
