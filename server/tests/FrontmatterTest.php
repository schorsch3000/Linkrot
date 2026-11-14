<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Custom\InvalidCustomFile;
use Linkrot\Pages\Frontmatter;
use PHPUnit\Framework\TestCase;

final class FrontmatterTest extends TestCase
{
    public function testWithoutAHeadTheWholeFileIsTheBody(): void
    {
        [$values, $body] = Frontmatter::parse("# Hello\n\ntext\n");

        self::assertSame(['title' => null, 'link' => null, 'order' => 100, 'published' => true, 'lang' => 'en'], $values);
        self::assertSame("# Hello\n\ntext\n", $body);
    }

    public function testAllKeys(): void
    {
        [$values, $body] = Frontmatter::parse("---\n# a comment\ntitle: Impressum\n\nlink: \"Impressum & Kontakt\"\norder: -5\npublished: false\nlang: de-AT\n---\nBody\n");

        self::assertSame(['title' => 'Impressum', 'link' => 'Impressum & Kontakt', 'order' => -5, 'published' => false, 'lang' => 'de-AT'], $values);
        self::assertSame("Body\n", $body);
    }

    public function testWindowsLineEndingsAndSingleQuotes(): void
    {
        [$values, $body] = Frontmatter::parse("---\r\ntitle: 'It''s'\r\n---\r\nText");

        self::assertSame("It''s", $values['title']);
        self::assertSame('Text', $body);
    }

    public function testADashLineLaterInTheTextIsNotAHead(): void
    {
        [$values, $body] = Frontmatter::parse("Text\n---\ntitle: x\n---\n");

        self::assertNull($values['title']);
        self::assertStringContainsString('title: x', $body);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'not closed' => ["---\ntitle: x\n", 'not closed'];
        yield 'unknown key' => ["---\ntitel: x\n---\n", 'line 2: unknown key `titel`'];
        yield 'twice' => ["---\ntitle: a\ntitle: b\n---\n", 'line 3: `title` is given twice'];
        yield 'no colon' => ["---\ntitle x\n---\n", 'line 2: expected `key: value`'];
        yield 'empty title' => ["---\ntitle:\n---\n", 'line 2: `title` must have 1 to 100'];
        yield 'long title' => ["---\ntitle: " . str_repeat('x', 101) . "\n---\n", '1 to 100'];
        yield 'long link' => ["---\nlink: " . str_repeat('x', 41) . "\n---\n", '1 to 40'];
        yield 'order text' => ["---\norder: first\n---\n", 'whole number'];
        yield 'order huge' => ["---\norder: 100000\n---\n", 'whole number'];
        yield 'published yes' => ["---\npublished: yes\n---\n", 'true or false'];
        yield 'bad lang' => ["---\nlang: German\n---\n", 'language code'];
        yield 'list' => ["---\ntitle:\n  - a\n---\n", 'line 2'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalid(string $source, string $message): void
    {
        try {
            Frontmatter::parse($source);
            self::fail('accepted');
        } catch (InvalidCustomFile $e) {
            self::assertStringContainsString($message, $e->getMessage());
            self::assertSame(400, $e->status);
        }
    }
}
