<?php

declare(strict_types=1);

namespace Linkrot\Mail;

use InvalidArgumentException;
use Linkrot\Tenants;

/**
 * Hands the mail to the local MTA through PHP's mail() (sendmail_path of php.ini: postfix, msmtp, ...). No SMTP client
 * of our own, no queue: the MTA is the queue.
 */
final readonly class SendmailMailer implements Mailer
{
    public function __construct(private string $from)
    {
        if (!Tenants::isValidEmail($from)) {
            throw new InvalidArgumentException('mail: not a plain address: ' . $from);
        }
    }

    public function send(string $to, string $subject, string $body): void
    {
        if (!Tenants::isValidEmail($to) || preg_match('~[\r\n]~', $subject) === 1) {
            throw new MailFailed('refusing to send: address or subject is not plain');
        }
        $headers = [
            'From' => $this->from,
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Auto-Submitted' => 'auto-generated',
        ];
        // The address is checked above, so quoting it for the shell (-f: the envelope sender) is belt and braces.
        $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . escapeshellarg($this->from));
        if (!$sent) {
            throw new MailFailed('mail() refused the message: is sendmail_path set and working?');
        }
    }
}
