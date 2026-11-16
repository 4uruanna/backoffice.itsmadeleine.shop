<?php

declare(strict_types=1);

namespace App\Service;

use App\Mail\MailBucket;
use App\Mail\MailMessage;
use App\Mail\MailTransport;
use App\Mail\RateLimitedException;

/**
 * Mail d'alerte groupé du cron (M7) : un seul mail par passage vers ALERT_EMAIL, qui liste
 * les nouvelles commandes en `error`. Texte seul, sans l'e-mail des clients (numéro de
 * commande et lien vers l'admin suffisent). Passe par le bucket (`kind = 'alert'`).
 */
final readonly class AlertMailer
{
    public const SENT = 'sent';
    public const NO_RECIPIENT = 'no_recipient';
    public const RATE_LIMITED = 'rate_limited';
    public const FAILED = 'failed';

    private const CODES = [
        'payload_errors' => 'livraison Shopify avec errors : commande non traitée (une livraison valide la reprendra)',
        'no_email' => 'commande sans e-mail client : aucun QR envoyé, retrait avec le numéro de commande et une pièce d\'identité',
        'max_attempts' => 'mail de retrait non envoyé après plusieurs essais : voir la dernière erreur',
    ];

    public function __construct(
        private MailTransport $transport,
        private MailBucket $bucket,
        private ?string $to,
        private ?string $storeHandle,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $errors lignes de PickupOrderRepository::findUnalertedErrors
     * @return array{self::SENT|self::NO_RECIPIENT|self::RATE_LIMITED|self::FAILED, string} issue et détail
     */
    public function send(array $errors): array
    {
        if ($this->to === null || $this->to === '') {
            return [self::NO_RECIPIENT, 'ALERT_EMAIL non renseignée, alerte non envoyée (voir var/log/alerts.log)'];
        }

        // Jeton pris avant l'envoi, comme pour les mails clients ; bucket vide → passage suivant.
        if (!$this->bucket->tryAcquire(null, MailBucket::KIND_ALERT)) {
            return [self::RATE_LIMITED, 'plafond d\'envoi atteint, alerte reportée au passage suivant'];
        }

        try {
            $this->transport->send($this->build($errors));
        } catch (RateLimitedException $e) {
            return [self::RATE_LIMITED, 'quota du serveur d\'envoi, alerte reportée : ' . mb_substr($e->getMessage(), 0, 200)];
        } catch (\Throwable $e) {
            return [self::FAILED, 'échec d\'envoi de l\'alerte, nouvel essai au passage suivant : ' . mb_substr($e->getMessage(), 0, 200)];
        }

        return [self::SENT, sprintf('alerte envoyée (%d commande%s)', count($errors), count($errors) > 1 ? 's' : '')];
    }

    /** @param list<array<string, mixed>> $errors */
    public function build(array $errors): MailMessage
    {
        $count = count($errors);
        $lines = [
            sprintf(
                '%d commande%s à retrait par QR %s en erreur depuis la dernière alerte.',
                $count,
                $count > 1 ? 's' : '',
                $count > 1 ? 'sont passées' : 'est passée',
            ),
            'Aucun nouvel essai automatique ne sera fait pour ces commandes.',
            '',
        ];

        foreach ($errors as $row) {
            $code = (string) ($row['error_code'] ?? '');
            $lines[] = sprintf('- Commande %s : %s', (string) $row['order_name'], $code !== '' ? $code : 'error');
            $lines[] = '  ' . (self::CODES[$code] ?? 'erreur sans code connu');
            $lines[] = sprintf('  Reçue le %s UTC, essais d\'envoi : %d', (string) $row['created_at'], (int) $row['attempts']);
            $lastError = trim((string) ($row['last_error'] ?? ''));
            if ($lastError !== '') {
                $lines[] = '  Dernière erreur : ' . mb_substr($lastError, 0, 300);
            }
            if ($this->storeHandle !== null && $this->storeHandle !== '') {
                $lines[] = sprintf(
                    '  https://admin.shopify.com/store/%s/orders/%s',
                    rawurlencode($this->storeHandle),
                    (string) $row['shopify_order_id'],
                );
            }
            $lines[] = '';
        }

        $lines[] = 'Suivi : docs/requetes-suivi.sql (phpMyAdmin). Détail : var/log/alerts.log sur l\'hébergement.';

        return new MailMessage(
            (string) $this->to,
            sprintf('[Retrait QR] %d commande%s en erreur', $count, $count > 1 ? 's' : ''),
            '',
            implode("\n", $lines) . "\n",
        );
    }
}
