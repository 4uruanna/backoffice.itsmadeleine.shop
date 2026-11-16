<?php

declare(strict_types=1);

namespace App\Mail;

interface MailTransport
{
    /**
     * @throws \RuntimeException en cas d'échec (message sans mot de passe)
     */
    public function send(MailMessage $message): void;
}
