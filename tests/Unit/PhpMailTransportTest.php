<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config;
use App\Mail\MailMessage;
use App\Mail\MailTransports;
use App\Mail\PhpMailTransport;
use App\Mail\SmtpTransport;
use PHPUnit\Framework\TestCase;

final class PhpMailTransportTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/fake-sendmail-' . bin2hex(random_bytes(4)) . '.eml';
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
    }

    /** Envoi dans un processus séparé : sendmail_path ne se règle qu'au démarrage de PHP. */
    private function send(bool $fail = false): string
    {
        $sendmail = dirname(__DIR__) . '/Support/fake-sendmail.sh';
        $process = proc_open(
            [PHP_BINARY, '-d', 'sendmail_path=' . $sendmail, dirname(__DIR__) . '/Support/phpmail-worker.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['FAKE_SENDMAIL_OUT' => $this->out, 'FAKE_SENDMAIL_FAIL' => $fail ? '1' : '0', 'PATH' => (string) getenv('PATH')],
        );
        $result = trim((string) stream_get_contents($pipes[1]));
        $errors = trim((string) stream_get_contents($pipes[2]));
        proc_close($process);
        self::assertSame('', $errors);

        return $result;
    }

    public function testMessageRemisASendmailAvecLeQrIntegre(): void
    {
        self::assertSame('ok', $this->send());

        // Fins de ligne CRLF dans le message : normalisées pour les expressions régulières.
        $raw = str_replace("\r\n", "\n", (string) file_get_contents($this->out));
        self::assertStringContainsString('-fretrait@itsmadeleine.shop', strtok($raw, "\n"), 'expéditeur d\'enveloppe (-f)');
        self::assertMatchesRegularExpression('/^From: It\'s Madeleine <retrait@itsmadeleine\.shop>$/m', $raw);
        self::assertMatchesRegularExpression('/^To: client@example\.com$/m', $raw);
        self::assertMatchesRegularExpression('/^Message-ID: <[^>]+@itsmadeleine\.shop>$/m', $raw);
        self::assertStringContainsString('multipart/related', $raw, 'image intégrée, pas en pièce jointe');
        self::assertMatchesRegularExpression('/^Content-ID: <qr-retrait>$/m', $raw);
        self::assertMatchesRegularExpression('/^Content-Disposition: inline; filename=qr-retrait\.png$/m', $raw);
        self::assertStringContainsString('text/plain', $raw);
    }

    public function testRefusDuServiceDEnvoi(): void
    {
        $result = $this->send(fail: true);

        self::assertStringStartsWith('RuntimeException: mail() : ', $result);
    }

    public function testConfigurationManquante(): void
    {
        $this->expectExceptionMessage('MAIL_FROM');
        (new PhpMailTransport('', ''))->send(new MailMessage('a@example.com', 's', '', 't'));
    }

    public function testChoixDuTransport(): void
    {
        self::assertInstanceOf(SmtpTransport::class, MailTransports::fromConfig(new Config([])), 'défaut : smtp');
        self::assertInstanceOf(PhpMailTransport::class, MailTransports::fromConfig(new Config(['MAIL_TRANSPORT' => 'phpmail'])));
        self::assertInstanceOf(PhpMailTransport::class, MailTransports::fromConfig(new Config(['MAIL_TRANSPORT' => 'PhpMail'])));
    }

    public function testTransportInconnuNEchoueQuALEnvoi(): void
    {
        $transport = MailTransports::fromConfig(new Config(['MAIL_TRANSPORT' => 'phpmial']));

        $this->expectExceptionMessage('MAIL_TRANSPORT inconnu');
        $transport->send(new MailMessage('a@example.com', 's', '', 't'));
    }
}
