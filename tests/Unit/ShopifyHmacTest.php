<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ShopifyHmac;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ShopifyHmacTest extends TestCase
{
    private const SECRET = 'secret-de-test';
    private const BODY = '{"data":{"order":{"name":"#1001"}}}';

    public function testSignatureValide(): void
    {
        $expected = base64_encode(hash_hmac('sha256', self::BODY, self::SECRET, true));

        self::assertTrue((new ShopifyHmac(self::SECRET))->isValid(self::BODY, $expected));
    }

    public function testSignatureInvalide(): void
    {
        $hmac = new ShopifyHmac(self::SECRET);
        $otherSecret = base64_encode(hash_hmac('sha256', self::BODY, 'autre-secret', true));

        self::assertFalse($hmac->isValid(self::BODY, $otherSecret));
        self::assertFalse($hmac->isValid(self::BODY . ' ', $hmac->sign(self::BODY)), 'corps modifié');
        self::assertFalse($hmac->isValid(self::BODY, 'pas-du-base64'));
    }

    public function testEnTeteAbsent(): void
    {
        $hmac = new ShopifyHmac(self::SECRET);

        self::assertFalse($hmac->isValid(self::BODY, null));
        self::assertFalse($hmac->isValid(self::BODY, ''));

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/');
        self::assertFalse($hmac->isValidRequest($request, self::BODY));
    }

    public function testLesDeuxNomsDEnTeteSontAcceptes(): void
    {
        $hmac = new ShopifyHmac(self::SECRET);
        $factory = new ServerRequestFactory();

        foreach (ShopifyHmac::HEADERS as $header) {
            $request = $factory->createServerRequest('POST', '/')->withHeader($header, $hmac->sign(self::BODY));
            self::assertTrue($hmac->isValidRequest($request, self::BODY), $header);
        }
    }

    public function testSecretVideRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShopifyHmac('');
    }
}
