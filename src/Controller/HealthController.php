<?php

declare(strict_types=1);

namespace App\Controller;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /health : 200 si la base répond, 503 sinon.
 * Indique aussi la version déployée (fichier VERSION écrit par scripts/build-release.sh)
 * et le transport d'envoi lu dans .env, pour vérifier un déploiement sans accès au serveur.
 */
final class HealthController
{
    /** @param \Closure(): PDO $connect */
    public function __construct(
        private readonly \Closure $connect,
        private readonly string $version = 'dev',
        private readonly string $mailTransport = 'smtp',
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            ($this->connect)()->query('SELECT 1')->fetchColumn();
            $status = 200;
            $body = ['status' => 'ok'];
        } catch (\Throwable $e) {
            error_log('health: base injoignable : ' . $e->getMessage());
            $status = 503;
            $body = ['status' => 'error'];
        }

        $body += ['version' => $this->version, 'mail_transport' => $this->mailTransport];
        $response->getBody()->write((string) json_encode($body));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
