<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Cli\MailCheck;
use Linkrot\Tests\Support\RecordingMailer;
use PHPUnit\Framework\TestCase;

final class MailCheckTest extends TestCase
{
    private string $file;
    private RecordingMailer $mailer;
    /** @var list<string> */
    private array $said = [];
    /** @var list<string> */
    private array $prompts = [];

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/linkrot-mailcheck-' . bin2hex(random_bytes(6)) . '.json';
        $this->mailer = new RecordingMailer();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** @param list<string|null> $answers what the operator types: the address, then codes; `code` stands for the mailed one */
    private function check(array $answers, string $from = 'lr@example.com'): MailCheck
    {
        return new MailCheck(
            $this->file,
            $from,
            $this->mailer,
            function (string $prompt) use (&$answers): ?string {
                $this->prompts[] = $prompt;
                $answer = array_shift($answers);

                return $answer === 'code' ? $this->mailedCode() : $answer;
            },
            function (string $line): void {
                $this->said[] = $line;
            },
        );
    }

    private function mailedCode(): string
    {
        if (preg_match('~\b(\d{6})\b~', $this->mailer->sent[0]['body'], $m) !== 1) {
            self::fail('no code');
        }

        return $m[1];
    }

    public function testVerifiesWithTheMailedCodeAndRemembersTheSender(): void
    {
        self::assertTrue($this->check(['me@example.com', 'code'])->run());
        self::assertSame('me@example.com', $this->mailer->sent[0]['to']);
        self::assertTrue($this->check([])->isVerified());
    }

    public function testAVerifiedSenderIsNotAskedAgain(): void
    {
        $this->check(['me@example.com', 'code'])->run();
        $this->mailer->sent = [];
        $this->prompts = [];

        self::assertTrue($this->check([])->run());
        self::assertSame([], $this->mailer->sent);
        self::assertSame([], $this->prompts);
    }

    public function testANewSenderIsVerifiedAgainAndTheOldOneStaysVerified(): void
    {
        $this->check(['me@example.com', 'code'])->run();
        $this->mailer->sent = [];

        self::assertFalse($this->check([], 'other@example.com')->isVerified());
        self::assertTrue($this->check(['me@example.com', 'code'], 'other@example.com')->run());
        self::assertTrue($this->check([])->isVerified());
        self::assertTrue($this->check([], 'other@example.com')->isVerified());
    }

    public function testNoAddressSkipsWithoutRememberingAnything(): void
    {
        foreach ([[''], [null]] as $answers) {
            self::assertFalse($this->check($answers)->run());
        }
        self::assertSame([], $this->mailer->sent);
        self::assertFileDoesNotExist($this->file);
    }

    public function testABadAddressSendsNothing(): void
    {
        self::assertFalse($this->check(['not an address'])->run());
        self::assertSame([], $this->mailer->sent);
    }

    public function testAFailedSendIsReportedAndNotRemembered(): void
    {
        $this->mailer->fail = true;
        self::assertFalse($this->check(['me@example.com'])->run());
        self::assertStringContainsString('FAILED', implode("\n", $this->said));
        self::assertFileDoesNotExist($this->file);
    }

    public function testWrongCodesGiveThreeTriesThenFail(): void
    {
        self::assertFalse($this->check(['me@example.com', '1', '2', '3', 'code'])->run());
        self::assertFileDoesNotExist($this->file);
    }

    public function testAnEmptyCodeGivesUp(): void
    {
        self::assertFalse($this->check(['me@example.com', ''])->run());
        self::assertFileDoesNotExist($this->file);
    }

    public function testTheCodeMayBeTypedWithSpaces(): void
    {
        self::assertTrue($this->check(['me@example.com', 'code'])->run());
    }
}
