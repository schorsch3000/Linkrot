<?php

declare(strict_types=1);

namespace Linkrot\Cli;

use Closure;
use Linkrot\Mail\Mailer;
use Linkrot\Tenants;
use Throwable;

/**
 * Proves once per sender that mail leaves this server: a code is mailed to an address the operator names and has to be
 * typed back. A sender that passed is remembered in a file next to global-config.php, so the question comes up again
 * only when mail.from changes.
 */
final readonly class MailCheck
{
    public const int ATTEMPTS = 3;

    /**
     * @param Closure(string): ?string $ask prompt to answer; null if nobody can be asked (no terminal, end of input)
     * @param Closure(string): void $say a line of output
     */
    public function __construct(
        private string $stateFile,
        private string $from,
        private Mailer $mailer,
        private Closure $ask,
        private Closure $say,
    ) {}

    public function isVerified(): bool
    {
        $state = is_file($this->stateFile) ? json_decode((string) file_get_contents($this->stateFile), true) : null;

        return is_array($state) && is_array($state['verified'] ?? null) && array_key_exists($this->from, $state['verified']);
    }

    /** @return bool true if the sender is verified now (before or just now) */
    public function run(): bool
    {
        if ($this->isVerified()) {
            ($this->say)("sender {$this->from} was verified before.");

            return true;
        }
        ($this->say)("sender {$this->from} has not been verified yet: a code is mailed to an address of yours.");
        $to = ($this->ask)('Send the test mail to (empty: skip): ');
        if ($to === null || trim($to) === '') {
            ($this->say)('skipped; run app/bin/check-mail to do it later.');

            return false;
        }
        $to = trim($to);
        if (!Tenants::isValidEmail($to)) {
            ($this->say)("not a plain address: $to");

            return false;
        }
        $code = sprintf('%06d', random_int(0, 999_999));
        try {
            $this->mailer->send($to, 'Linkrot: test mail', "The code to confirm that {$this->from} can send mail is\n\n    $code\n");
        } catch (Throwable $e) {
            ($this->say)('FAILED to send: ' . $e->getMessage());

            return false;
        }
        ($this->say)("sent to $to. Mail can take a minute.");
        for ($i = 0; $i < self::ATTEMPTS; ++$i) {
            $typed = ($this->ask)('Code (empty: give up): ');
            $typed = $typed === null ? '' : preg_replace('~\s+~', '', $typed);
            if ($typed === '' || $typed === null) {
                break;
            }
            if (hash_equals($code, $typed)) {
                $this->remember();
                ($this->say)("sender {$this->from} verified.");

                return true;
            }
            ($this->say)('that is not the code.');
        }
        ($this->say)('not verified; check sendmail_path, the spam folder and the mail log, then run app/bin/check-mail.');

        return false;
    }

    private function remember(): void
    {
        $state = is_file($this->stateFile) ? json_decode((string) file_get_contents($this->stateFile), true) : null;
        $verified = is_array($state) && is_array($state['verified'] ?? null) ? $state['verified'] : [];
        $verified[$this->from] = time();
        file_put_contents($this->stateFile, json_encode(['verified' => $verified], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n", LOCK_EX);
    }
}
