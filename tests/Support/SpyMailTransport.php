<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Mail\MailMessage;
use App\Mail\MailTransport;

final class SpyMailTransport implements MailTransport
{
    /** @var list<MailMessage> */
    public array $sent = [];
    /** @var list<string> messages d'erreur à lever, un par envoi, avant de réussir */
    public array $failures = [];
    /** @var (\Closure(MailMessage): void)|null appelé avant l'envoi (simuler une annulation simultanée) */
    public ?\Closure $before = null;

    public function send(MailMessage $message): void
    {
        if ($this->before !== null) {
            ($this->before)($message);
        }
        if ($this->failures !== []) {
            throw new \RuntimeException(array_shift($this->failures));
        }
        $this->sent[] = $message;
    }
}
