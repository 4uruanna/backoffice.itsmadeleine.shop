<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AppFactory;
use App\Config;
use App\Service\ShopifyHmac;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class AppTest extends TestCase
{
    private const SECRET = 'secret-de-test';
    private const PROBE = '/webhooks/shopify/order-paid';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/backoffice-test-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $dir = $this->root . '/var/log/deliveries';
        foreach (glob($dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        foreach ([$dir, $this->root . '/var/log', $this->root . '/var', $this->root] as $path) {
            if (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    public function testHealthOk(): void
    {
        $response = $this->handle($this->app(connect: fn () => new PDO('sqlite::memory:')), 'GET', '/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', (string) $response->getBody());
    }

    public function testHealthBaseInjoignable(): void
    {
        $app = $this->app(connect: fn () => throw new \PDOException('connexion refusée'));
        $previous = ini_set('error_log', '/dev/null');
        try {
            $response = $this->handle($app, 'GET', '/health');
        } finally {
            ini_set('error_log', (string) $previous);
        }

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"status":"error"}', (string) $response->getBody());
    }

    public function testSondeAccepteUneLivraisonSigneeEtLaJournalise(): void
    {
        $body = '{"data":{"order":{"name":"#1001","email":"client@example.com"}}}';
        $response = $this->handle($this->app(), 'POST', self::PROBE, $body, [
            'Shopify-Hmac-Sha256' => (new ShopifyHmac(self::SECRET))->sign($body),
            'Shopify-Webhook-Id' => 'abc/../123',
            'Shopify-Topic' => 'Order',
            'User-Agent' => 'Shopify',
        ]);

        self::assertSame(200, $response->getStatusCode());

        $files = glob($this->root . '/var/log/deliveries/*.json') ?: [];
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('#/\d{8}-\d{6}-abc_\.\._123\.json$#', $files[0]);

        $record = json_decode((string) file_get_contents($files[0]), true);
        self::assertSame('#1001', $record['body']['data']['order']['name']);
        self::assertSame('Order', $record['headers']['Shopify-Topic']);
        self::assertArrayNotHasKey('User-Agent', $record['headers']);
    }

    public function testSondeRefuseUneLivraisonNonSignee(): void
    {
        $response = $this->handle($this->app(), 'POST', self::PROBE, '{}');

        self::assertSame(401, $response->getStatusCode());
        self::assertDirectoryDoesNotExist($this->root . '/var/log/deliveries');
    }

    public function testSondeRefuseUneMauvaiseSignature(): void
    {
        $response = $this->handle($this->app(), 'POST', self::PROBE, '{}', [
            'Shopify-Hmac-Sha256' => (new ShopifyHmac('autre-secret'))->sign('{}'),
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testSondeSansSecretRepond500PourQueShopifyReessaie(): void
    {
        $previous = ini_set('error_log', '/dev/null');
        try {
            $response = $this->handle($this->app(secret: null), 'POST', self::PROBE, '{}', [
                'Shopify-Hmac-Sha256' => 'x',
            ]);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        self::assertSame(500, $response->getStatusCode());
    }

    private function app(?string $secret = self::SECRET, ?\Closure $connect = null): App
    {
        $values = ['APP_ENV' => 'test'];
        if ($secret !== null) {
            $values['SHOPIFY_CLIENT_SECRET'] = $secret;
        }

        return AppFactory::create(new Config($values), $this->root, $connect ?? fn () => new PDO('sqlite::memory:'));
    }

    /** @param array<string, string> $headers */
    private function handle(App $app, string $method, string $path, string $body = '', array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withBody((new StreamFactory())->createStream($body));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }
}
