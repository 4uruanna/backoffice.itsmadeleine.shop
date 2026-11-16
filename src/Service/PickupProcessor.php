<?php

declare(strict_types=1);

namespace App\Service;

use App\Mail\MailBucket;
use App\Mail\MailTransport;
use App\Mail\QrMailBuilder;
use App\Mail\RateLimitedException;
use App\Repository\PickupOrderRepository;

/**
 * Traitement d'une commande à retrait par QR (M5) : prise en charge atomique, jeton, mail.
 * Appelé après la réponse à Shopify (DeferredTasks), puis par le cron de rattrapage (M7).
 * M6 : jeton du bucket pris juste avant l'envoi ; bucket vide ou quota SMTP dépassé →
 * la commande attend (`pending` / `rate_limited`), sans compter d'essai.
 */
final readonly class PickupProcessor
{
    /**
     * @param list<string> $allowlist si non vide, seuls ces destinataires reçoivent le mail
     *                                (les autres restent `pending` / `not_allowlisted`) : garde-fou avant la mise en service
     */
    public function __construct(
        private PickupOrderRepository $orders,
        private QrMailBuilder $mails,
        private MailTransport $transport,
        private MailBucket $bucket,
        private AlertLog $alerts,
        private int $maxAttempts = 3,
        private array $allowlist = [],
    ) {
    }

    /** @return string résumé pour le journal (aucune donnée personnelle) */
    public function process(int $id): string
    {
        return $this->processResult($id)->message;
    }

    /** Traite la ligne et renvoie son issue (le cron s'arrête au premier `rate_limited`). */
    public function processResult(int $id): ProcessResult
    {
        if (!$this->orders->claim($id)) {
            return new ProcessResult(ProcessResult::SKIPPED, "ligne $id : rien à traiter (déjà prise, envoyée ou révoquée)");
        }

        $row = $this->orders->findById($id);
        \assert($row !== null);
        $email = $row['customer_email'] === null ? null : (string) $row['customer_email'];
        if ($email === null || $email === '') {
            // Normalement impossible : sans e-mail, la ligne est créée en error/no_email (M3).
            $this->orders->release($id, 'no_email');

            return new ProcessResult(ProcessResult::WAITING, "ligne $id : sans e-mail, laissée en attente");
        }

        if ($this->allowlist !== [] && !in_array(strtolower($email), $this->allowlist, true)) {
            $this->orders->release($id, 'not_allowlisted');

            return new ProcessResult(ProcessResult::WAITING, "ligne $id : destinataire hors MAIL_ALLOWLIST, laissée en attente");
        }

        try {
            // Jeton enregistré avant l'envoi : un nouvel essai réutilise le même QR.
            $token = $this->orders->assignToken($id, QrGenerator::newToken());
            \assert($token !== null);
            $message = $this->mails->build($email, (string) $row['order_name'], $row['status_page_url'], $token);

            // Dernier contrôle juste avant l'envoi : annulée pendant le traitement → aucun mail.
            if (($this->orders->findById($id)['revoked_at'] ?? null) !== null) {
                $this->orders->release($id, 'revoked');

                return new ProcessResult(ProcessResult::WAITING, "ligne $id : révoquée avant l'envoi, aucun mail");
            }

            if (!$this->bucket->tryAcquire($id, MailBucket::KIND_QR)) {
                $this->orders->release($id, 'rate_limited');

                return new ProcessResult(ProcessResult::RATE_LIMITED, "ligne $id : plafond d'envoi atteint, en attente du cron");
            }

            $this->transport->send($message);
        } catch (RateLimitedException $e) {
            // Quota du serveur SMTP : ni échec ni essai compté, le cron reprendra la commande.
            $this->orders->release($id, 'rate_limited', $e->getMessage());

            return new ProcessResult(
                ProcessResult::RATE_LIMITED,
                "ligne $id : quota SMTP dépassé, en attente du cron : " . mb_substr($e->getMessage(), 0, 200),
            );
        } catch (\Throwable $e) {
            $status = $this->orders->markFailed($id, $e->getMessage(), $this->maxAttempts);
            if ($status === 'error') {
                $this->alerts->alert('max_attempts', 'Mail de retrait non envoyé après plusieurs essais', [
                    'pickup_order_id' => $id,
                    'order_name' => (string) $row['order_name'],
                    'last_error' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }

            return new ProcessResult(
                $status === 'error' ? ProcessResult::ERROR : ProcessResult::FAILED,
                "ligne $id : échec d'envoi ($status) : " . mb_substr($e->getMessage(), 0, 200),
            );
        }

        $this->orders->markSent($id);

        return new ProcessResult(ProcessResult::SENT, "ligne $id : mail envoyé");
    }
}
