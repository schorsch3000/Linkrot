<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use InvalidArgumentException;
use Linkrot\Config;
use Linkrot\Mail\MailFailed;
use Linkrot\Mail\SendmailMailer;
use Linkrot\Tenants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MailConfigTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/linkrot-mail-' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function addresses(): iterable
    {
        yield 'plain' => ['me@example.com', true];
        yield 'plus and dots' => ['first.last+lr@mail.example.org', true];
        yield 'no domain dot' => ['me@localhost', false];
        yield 'display name' => ['Me <me@example.com>', false];
        yield 'two recipients' => ['a@example.com,b@example.com', false];
        yield 'newline' => ["me@example.com\nBcc: x@example.com", false];
        yield 'space' => ['me @example.com', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('addresses')]
    public function testIsValidEmail(string $address, bool $valid): void
    {
        self::assertSame($valid, Tenants::isValidEmail($address));
    }

    public function testGlobalConfigReadsTheSender(): void
    {
        file_put_contents($this->file, "<?php return ['hosts' => ['a.example'], 'mail' => ['from' => 'lr@example.com']];\n");
        self::assertSame('lr@example.com', Tenants::load($this->file)->mailFrom);
        file_put_contents($this->file, "<?php return ['hosts' => ['a.example']];\n");
        self::assertNull(Tenants::load($this->file)->mailFrom);
    }

    #[DataProvider('badMail')]
    public function testGlobalConfigRefusesBadMail(string $mail): void
    {
        file_put_contents($this->file, "<?php return ['hosts' => ['a.example'], 'mail' => $mail];\n");
        $this->expectException(InvalidArgumentException::class);
        Tenants::load($this->file);
    }

    /** @return iterable<string, array{string}> */
    public static function badMail(): iterable
    {
        yield 'string' => ["'lr@example.com'"];
        yield 'no from' => ['[]'];
        yield 'bad address' => ["['from' => 'Lr <lr@example.com>']"];
        yield 'unknown key' => ["['from' => 'lr@example.com', 'smtp' => 'x']"];
    }

    public function testEnrollingAHostKeepsTheMailSettings(): void
    {
        file_put_contents($this->file, "<?php return ['hosts' => ['a.example'], 'mail' => ['from' => 'lr@example.com']];\n");
        self::assertTrue(Tenants::enroll($this->file, 'b.example'));
        $tenants = Tenants::load($this->file);
        self::assertSame(['a.example', 'b.example'], $tenants->hosts);
        self::assertSame('lr@example.com', $tenants->mailFrom);
    }

    public function testHostConfigEmailIsLowerCasedAndValidated(): void
    {
        self::assertSame('me@example.com', Config::fromArray(['email' => 'Me@Example.COM'], 'https://x.example')->email);
        self::assertNull(Config::fromArray([], 'https://x.example')->email);
        foreach ([['email' => 'nope'], ['email' => 5]] as $values) {
            try {
                Config::fromArray($values, 'https://x.example');
                self::fail('accepted ' . json_encode($values));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMailerRefusesWhatIsNotPlain(): void
    {
        $mailer = new SendmailMailer('lr@example.com');
        foreach ([['a@example.com,b@example.com', 'x'], ['a@example.com', "x\nBcc: y@example.com"]] as [$to, $subject]) {
            try {
                $mailer->send($to, $subject, 'body');
                self::fail('sent');
            } catch (MailFailed) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new SendmailMailer('Lr <lr@example.com>');
    }
}
