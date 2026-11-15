<?php

declare(strict_types=1);

namespace Linkrot\Tests\Support;

use Linkrot\Mail\Mailer;
use Linkrot\Mail\MailFailed;

/** Keeps the mails instead of sending them. */
final class RecordingMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $sent = [];
    public bool $fail = false;

    public function send(string $to, string $subject, string $body): void
    {
        if ($this->fail) {
            throw new MailFailed('down');
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }

    /** The code of the last mail, as typed from it. */
    public function lastCode(): string
    {
        $last = $this->sent[array_key_last($this->sent) ?? throw new \LogicException('no mail')];
        if (preg_match('~\b([A-Z2-9]{4}-[A-Z2-9]{4})\b~', $last['body'], $m) !== 1) {
            throw new \LogicException('no code in mail');
        }

        return $m[1];
    }
}
