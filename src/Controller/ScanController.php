<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PickupOrderRepository;
use App\Repository\ScanRepository;
use App\Service\QrGenerator;
use App\Service\Template;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /r/{token} : scan du QR en boutique (M4).
 *
 * - jeton mal formé ou inconnu → 404, même page neutre dans les deux cas ;
 * - commande valide → scan `redirected`, 302 vers la commande dans l'admin Shopify ;
 * - commande annulée ou remboursée → scan `revoked`, page d'avertissement « ne pas remettre ».
 *
 * Le lien admin ne s'ouvre qu'avec une session Shopify : sans, page de connexion Shopify.
 */
final readonly class ScanController
{
    private const REVOKED_LABELS = ['cancelled' => 'annulée', 'refunded' => 'remboursée'];

    public function __construct(
        private PickupOrderRepository $orders,
        private ScanRepository $scans,
        private Template $templates,
        private ?string $storeHandle,
        private string $displayTimezone = 'Europe/Paris',
    ) {
    }

    /** @param array{token?: string} $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $args['token'] ?? '';
        $order = QrGenerator::isValidToken($token) ? $this->orders->findByToken($token) : null;
        if ($order === null) {
            return $this->page($response->withStatus(404), 'scan/not-found.html.php', []);
        }

        if ($this->storeHandle === null) {
            // Sans identifiant de boutique, pas de lien admin : on n'invente pas d'URL.
            throw new \RuntimeException('Configuration manquante : SHOPIFY_STORE_HANDLE');
        }

        $adminUrl = sprintf(
            'https://admin.shopify.com/store/%s/orders/%s',
            rawurlencode($this->storeHandle),
            (string) $order['shopify_order_id'],
        );
        $revoked = $order['revoked_at'] !== null;

        $this->record((int) $order['id'], $revoked ? ScanRepository::REVOKED : ScanRepository::REDIRECTED, $request);

        if (!$revoked) {
            return self::secure($response)->withStatus(302)->withHeader('Location', $adminUrl);
        }

        return $this->page($response, 'scan/revoked.html.php', [
            'orderName' => (string) $order['order_name'],
            'reason' => self::REVOKED_LABELS[(string) $order['revoked_reason']] ?? 'annulée',
            'revokedAt' => $this->localDate((string) $order['revoked_at']),
            'adminUrl' => $adminUrl,
        ]);
    }

    /** Un journal de scan en échec ne doit pas empêcher le staff d'ouvrir la commande. */
    private function record(int $pickupOrderId, string $result, ServerRequestInterface $request): void
    {
        $server = $request->getServerParams();
        $ip = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : null;
        $userAgent = $request->getHeaderLine('User-Agent');

        try {
            $this->scans->record($pickupOrderId, $result, $ip, $userAgent === '' ? null : $userAgent);
        } catch (\Throwable $e) {
            error_log("scan: journal impossible pour la ligne $pickupOrderId ($result) : " . $e->getMessage());
        }
    }

    private function localDate(string $utc): string
    {
        $date = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone($this->displayTimezone));

        return $date->format('d/m/Y') . ' à ' . $date->format('H:i');
    }

    /** @param array<string, mixed> $variables */
    private function page(ResponseInterface $response, string $template, array $variables): ResponseInterface
    {
        $response->getBody()->write($this->templates->render($template, $variables));

        return self::secure($response)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            // Page autonome : styles en ligne seulement, aucune ressource externe.
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
    }

    /** En-têtes communs : ni cache, ni indexation, ni jeton transmis à admin.shopify.com. */
    private static function secure(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
