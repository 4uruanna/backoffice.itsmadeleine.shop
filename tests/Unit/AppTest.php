<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AppFactory;
use App\Config;
use App\Service\DeferredTasks;
use App\Service\ShopifyHmac;
use App\Tests\Support\InMemoryPickupOrderRepository;
use App\Tests\Support\Payloads;
use App\Tests\Support\SpyAlertLog;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class AppTest extends TestCase
{
    private const SECRET = 'secret-de-test';
    private const ROUTE = '/webhooks/shopify/order-paid';

    private string $root;
    private InMemoryPickupOrderRepository $orders;
    private DeferredTasks $tasks;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/backoffice-test-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        $this->orders = new InMemoryPickupOrderRepository();
        $this->tasks = new DeferredTasks();
        // Les contrôleurs journalisent chaque livraison : pas dans la sortie des tests.
        $this->previousErrorLog = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousErrorLog);
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

        $response = $this->handle($app, 'GET', '/health');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"status":"error"}', (string) $response->getBody());
    }

    public function testLivraisonSigneeCreeLaCommandeEtProgrammeLeTraitement(): void
    {
        $response = $this->post($this->app(), json_encode(Payloads::order(action: 'create')), signed: true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('pending', $this->orders->findByShopifyOrderId(8264632992077)['status']);
        self::assertSame('7135bed4', $this->orders->findByShopifyOrderId(8264632992077)['webhook_id']);
        self::assertSame(1, $this->tasks->count());
        self::assertDirectoryDoesNotExist($this->root . '/var/log/deliveries', 'copie brute désactivée par défaut');
    }

    public function testCreatePuisUpdateSimultanesUneSeuleLigne(): void
    {
        $app = $this->app();
        $this->post($app, json_encode(Payloads::order(action: 'create')), signed: true);
        $response = $this->post($app, json_encode(Payloads::order(action: 'update')), signed: true);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->orders->rows);
        self::assertSame(1, $this->tasks->count());
    }

    public function testLivraisonNonSigneeRefuseeSansEcriture(): void
    {
        $response = $this->post($this->app(debugDeliveries: true), json_encode(Payloads::order()));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $this->orders->rows);
        self::assertSame(0, $this->tasks->count());
        self::assertDirectoryDoesNotExist($this->root . '/var/log/deliveries');
    }

    public function testMauvaiseSignatureRefusee(): void
    {
        $body = json_encode(Payloads::order());
        $response = $this->post($this->app(), $body, headers: [
            'Shopify-Hmac-Sha256' => (new ShopifyHmac('autre-secret'))->sign($body),
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $this->orders->rows);
    }

    public function testCorpsIllisibleMaisSigneRepond200(): void
    {
        $response = $this->post($this->app(), 'pas du json', signed: true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->orders->rows);
    }

    public function testCopieBruteDesLivraisonsSiDebugDeliveries(): void
    {
        $body = json_encode(Payloads::order());
        $this->post($this->app(debugDeliveries: true), $body, signed: true);

        $files = glob($this->root . '/var/log/deliveries/*.json') ?: [];
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('#/\d{8}-\d{6}-7135bed4\.json$#', $files[0]);
        $record = json_decode((string) file_get_contents($files[0]), true);
        self::assertSame($body, $record['raw_body']);
        self::assertSame('Order', $record['headers']['Shopify-Topic']);
    }

    public function testSansSecretRepond500PourQueShopifyReessaie(): void
    {
        $response = $this->post($this->app(secret: null), '{}', headers: ['Shopify-Hmac-Sha256' => 'x']);

        self::assertSame(500, $response->getStatusCode());
    }

    private function app(?string $secret = self::SECRET, ?\Closure $connect = null, bool $debugDeliveries = false): App
    {
        $values = ['APP_ENV' => 'test', 'DEBUG_DELIVERIES' => $debugDeliveries ? '1' : '0'];
        if ($secret !== null) {
            $values['SHOPIFY_CLIENT_SECRET'] = $secret;
        }

        return AppFactory::create(
            new Config($values),
            $this->root,
            $connect ?? fn () => new PDO('sqlite::memory:'),
            $this->tasks,
            $this->orders,
            new SpyAlertLog(),
        );
    }

    /** @param array<string, string> $headers */
    private function post(App $app, string $body, bool $signed = false, array $headers = []): ResponseInterface
    {
        $headers += ['Shopify-Webhook-Id' => '7135bed4', 'Shopify-Topic' => 'Order', 'User-Agent' => 'Shopify'];
        if ($signed) {
            $headers['Shopify-Hmac-Sha256'] = (new ShopifyHmac(self::SECRET))->sign($body);
        }

        return $this->handle($app, 'POST', self::ROUTE, $body, $headers);
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
