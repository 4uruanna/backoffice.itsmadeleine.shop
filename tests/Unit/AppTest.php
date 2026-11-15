<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AppFactory;
use App\Config;
use App\Domain\OrderSnapshot;
use App\Service\DeferredTasks;
use App\Service\QrGenerator;
use App\Service\Template;
use App\Service\ShopifyHmac;
use App\Tests\Support\InMemoryPickupOrderRepository;
use App\Tests\Support\InMemoryScanRepository;
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
    private InMemoryScanRepository $scans;
    private DeferredTasks $tasks;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/backoffice-test-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        $this->orders = new InMemoryPickupOrderRepository();
        $this->scans = new InMemoryScanRepository();
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

    public function testTraitementDiffereAttribueUnJetonStable(): void
    {
        $this->post($this->app(), json_encode(Payloads::order()), signed: true);
        $this->tasks->run();

        $token = $this->orders->findByShopifyOrderId(8264632992077)['token'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        // Nouvel essai (cron, M7) : même jeton, donc même QR.
        $this->orders->assignToken($this->orders->findByShopifyOrderId(8264632992077)['id'], str_repeat('f', 32));
        self::assertSame($token, $this->orders->findByShopifyOrderId(8264632992077)['token']);
    }

    public function testScanRedirigeVersLaCommandeDansLAdmin(): void
    {
        $token = $this->orderWithToken();

        $response = $this->handle($this->app(), 'GET', '/r/' . $token, headers: ['User-Agent' => 'iPhone Safari']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://admin.shopify.com/store/itsmadeleine-love-2/orders/8264632992077', $response->getHeaderLine('Location'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertStringContainsString('noindex', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame([[
            'pickup_order_id' => 1, 'result' => 'redirected', 'ip' => '203.0.113.7', 'user_agent' => 'iPhone Safari',
        ]], $this->scans->scans);
    }

    public function testScanCommandeAnnuleePageDAvertissement(): void
    {
        $token = $this->orderWithToken();
        $this->orders->rows[8264632992077]['revoked_at'] = '2026-11-14 20:05:00';
        $this->orders->rows[8264632992077]['revoked_reason'] = 'cancelled';

        $response = $this->handle($this->app(), 'GET', '/r/' . $token);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Ne pas remettre la commande', $html);
        self::assertStringContainsString('Commande #1094 annulée', $html);
        self::assertStringContainsString('14/11/2026 à 21:05', $html, 'heure de Paris');
        self::assertStringContainsString('href="https://admin.shopify.com/store/itsmadeleine-love-2/orders/8264632992077"', $html);
        self::assertStringContainsString('noindex', $html);
        self::assertStringNotContainsString('@', $html, 'aucune adresse e-mail');
        self::assertDoesNotMatchRegularExpression('#(src|href)="(?!https://admin\.shopify\.com/)#', $html, 'aucune ressource externe');
        self::assertSame('revoked', $this->scans->scans[0]['result']);
    }

    public function testScanCommandeRemboursee(): void
    {
        $token = $this->orderWithToken();
        $this->orders->rows[8264632992077]['revoked_at'] = '2026-07-01 08:00:00';
        $this->orders->rows[8264632992077]['revoked_reason'] = 'refunded';

        $html = (string) $this->handle($this->app(), 'GET', '/r/' . $token)->getBody();

        self::assertStringContainsString('Commande #1094 remboursée', $html);
        self::assertStringContainsString('01/07/2026 à 10:00', $html, 'heure d\'été de Paris');
    }

    public function testNomDeCommandeEchappe(): void
    {
        $token = $this->orderWithToken();
        $this->orders->rows[8264632992077]['order_name'] = '<script>x</script>';
        $this->orders->rows[8264632992077]['revoked_at'] = '2026-11-14 20:05:00';
        $this->orders->rows[8264632992077]['revoked_reason'] = 'cancelled';

        $html = (string) $this->handle($this->app(), 'GET', '/r/' . $token)->getBody();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testJetonInconnuOuMalFormePageNeutre(): void
    {
        $this->orderWithToken();
        $app = $this->app();

        $unknown = $this->handle($app, 'GET', '/r/' . str_repeat('0', 32));
        $malformed = $this->handle($app, 'GET', '/r/pas-un-jeton');
        $uppercase = $this->handle($app, 'GET', '/r/' . str_repeat('A', 32));

        foreach ([$unknown, $malformed, $uppercase] as $response) {
            self::assertSame(404, $response->getStatusCode());
            self::assertSame((string) $unknown->getBody(), (string) $response->getBody(), 'même page');
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        }
        self::assertStringContainsString('QR code non reconnu', (string) $unknown->getBody());
        self::assertSame([], $this->scans->scans);
    }

    public function testJournalDeScanEnEchecNEmpechePasLaRedirection(): void
    {
        $token = $this->orderWithToken();
        $this->scans->fail = true;

        $response = $this->handle($this->app(), 'GET', '/r/' . $token);

        self::assertSame(302, $response->getStatusCode());
    }

    public function testUserAgentTronque(): void
    {
        $token = $this->orderWithToken();

        $this->handle($this->app(), 'GET', '/r/' . $token, headers: ['User-Agent' => str_repeat('a', 400)]);

        self::assertSame(255, strlen($this->scans->scans[0]['user_agent']));
    }

    public function testScanSansIdentifiantDeBoutiqueRepond500(): void
    {
        $token = $this->orderWithToken();

        $response = $this->handle($this->app(storeHandle: null), 'GET', '/r/' . $token);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame([], $this->scans->scans, 'rien de journalisé sans lien à ouvrir');
    }

    public function testSansSecretRepond500PourQueShopifyReessaie(): void
    {
        $response = $this->post($this->app(secret: null), '{}', headers: ['Shopify-Hmac-Sha256' => 'x']);

        self::assertSame(500, $response->getStatusCode());
    }

    private function app(
        ?string $secret = self::SECRET,
        ?\Closure $connect = null,
        bool $debugDeliveries = false,
        ?string $storeHandle = 'itsmadeleine-love-2',
    ): App {
        $values = ['APP_ENV' => 'test', 'DEBUG_DELIVERIES' => $debugDeliveries ? '1' : '0'];
        if ($secret !== null) {
            $values['SHOPIFY_CLIENT_SECRET'] = $secret;
        }
        if ($storeHandle !== null) {
            $values['SHOPIFY_STORE_HANDLE'] = $storeHandle;
        }

        return AppFactory::create(
            new Config($values),
            $this->root,
            $connect ?? fn () => new PDO('sqlite::memory:'),
            $this->tasks,
            $this->orders,
            new SpyAlertLog(),
            $this->scans,
            new Template(dirname(__DIR__, 2) . '/templates'),
        );
    }

    /** Commande #1094 enregistrée et traitée (jeton attribué). */
    private function orderWithToken(): string
    {
        $id = $this->orders->insertIfAbsent(8264632992077, new OrderSnapshot(8264632992077, '#1094', 'client@example.com', 'https://example.myshopify.com/s', 'PAID', null, ['retrait-qr']), 'pending', null, null, 'wh');
        \assert($id !== null);

        return (string) $this->orders->assignToken($id, QrGenerator::newToken());
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
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '203.0.113.7'])
            ->withBody((new StreamFactory())->createStream($body));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }
}
