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
    /** @param string|null $name shown as the sender next to the address; plain text (global-config.php checks it) */
    public function __construct(private string $from, private ?string $name = null)
    {
        if (!Tenants::isValidEmail($from)) {
            throw new InvalidArgumentException('mail: not a plain address: ' . $from);
        }
        if ($name !== null && preg_match('~[<>"\\\\\x00-\x1f\x7f]~', $name) === 1) {
            throw new InvalidArgumentException('mail: the sender name must not contain quotes, angle brackets or control characters');
        }
    }

    public function send(string $to, string $subject, string $body): void
    {
        if (!Tenants::isValidEmail($to) || preg_match('~[\r\n]~', $subject) === 1) {
            throw new MailFailed('refusing to send: address or subject is not plain');
        }
        $headers = [
            'From' => $this->fromHeader(),
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Auto-Submitted' => 'auto-generated',
        ];
        // The address is checked above, so quoting it for the shell (-f: the envelope sender) is belt and braces.
        $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . escapeshellarg($this->from));
        if (!$sent) {
            throw new MailFailed('mail() refused the message: is sendmail_path set and working?');
        }
    }

    /** The From header: `address`, or `"Name" <address>` (a name with other than plain ASCII is encoded). */
    public function fromHeader(): string
    {
        return $this->name === null ? $this->from : $this->name() . " <{$this->from}>";
    }

    /** The name as a header word: plain ASCII is quoted, anything else is encoded. */
    private function name(): string
    {
        $name = (string) $this->name;

        return preg_match('~^[\x20-\x7e]+$~', $name) === 1 ? '"' . $name . '"' : '=?UTF-8?B?' . base64_encode($name) . '?=';
    }
}
