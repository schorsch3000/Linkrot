<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Config;
use Linkrot\DownloadMode;
use Linkrot\Settings;
use Linkrot\Tests\Support\AppTestCase;
use Linkrot\Web\HeaderTaglines;

final class SettingsTest extends AppTestCase
{
    protected function config(): Config
    {
        return new Config(baseUrl: 'https://lr.example', chunkSize: 10, maxRmAfter: '30d', downloadMode: DownloadMode::Xaccel);
    }

    /** @param array<string, bool> $changes */
    private function change(array $changes): void
    {
        self::assertSame(200, $this->request('PATCH', '/api/v1/settings', json: $changes)->status);
    }

    private function page(): string
    {
        $id = $this->upload('0123456789', 'a.txt');

        return $this->request('GET', "/file/$id/a.txt", auth: false)->body;
    }

    public function testAllOnByDefault(): void
    {
        $response = $this->request('GET', '/api/v1/settings');

        self::assertSame(200, $response->status);
        self::assertSame(['settings' => array_fill_keys(Settings::KEYS, true)], json_decode($response->body, true));
    }

    public function testNeedsAuthentication(): void
    {
        self::assertSame(401, $this->request('GET', '/api/v1/settings', auth: false)->status);
        self::assertSame(401, $this->request('PATCH', '/api/v1/settings', json: ['show_title' => false], auth: false)->status);
    }

    public function testPatchChangesOnlyWhatIsSentAndKeepsIt(): void
    {
        $this->change(['show_title' => false]);
        $this->change(['show_curl_line' => false]);

        $settings = json_decode($this->request('GET', '/api/v1/settings')->body, true)['settings'];
        self::assertFalse($settings['show_title']);
        self::assertFalse($settings['show_curl_line']);
        self::assertTrue($settings['show_taglines']);

        $this->change(['show_title' => true]);
        self::assertTrue(json_decode($this->request('GET', '/api/v1/settings')->body, true)['settings']['show_title']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badBodies(): iterable
    {
        yield 'empty' => [[]];
        yield 'unknown key' => [['show_nothing' => true]];
        yield 'known and unknown key' => [['show_title' => false, 'nope' => true]];
        yield 'string' => [['show_title' => 'no']];
        yield 'int' => [['show_title' => 0]];
        yield 'null' => [['show_title' => null]];
    }

    /** @param array<string, mixed> $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('badBodies')]
    public function testBadBodiesAreRefusedAndChangeNothing(array $body): void
    {
        $response = $this->request('PATCH', '/api/v1/settings', json: $body);

        self::assertSame(400, $response->status);
        self::assertSame(array_fill_keys(Settings::KEYS, true), json_decode($this->request('GET', '/api/v1/settings')->body, true)['settings']);
    }

    public function testNoJsonObjectIsRefused(): void
    {
        self::assertSame(400, $this->request('PATCH', '/api/v1/settings', raw: 'nonsense')->status);
    }

    public function testTaglinesOffRemoveFooterAndHeader(): void
    {
        $id = $this->upload('0123456789', 'a.txt');
        self::assertStringContainsString('<footer>', $this->request('GET', "/file/$id/a.txt", auth: false)->body);
        self::assertArrayHasKey(HeaderTaglines::HEADER, $this->request('GET', "/dl/$id/a.txt", auth: false)->headers);

        $this->change(['show_taglines' => false]);

        self::assertStringNotContainsString('<footer>', $this->request('GET', "/file/$id/a.txt", auth: false)->body);
        self::assertStringNotContainsString('<footer>', $this->request('GET', '/', auth: false)->body);
        self::assertStringNotContainsString('<footer>', $this->request('GET', '/file/nope', auth: false)->body);
        self::assertArrayNotHasKey(HeaderTaglines::HEADER, $this->request('GET', "/dl/$id/a.txt", auth: false)->headers);
    }

    public function testStartpageOffIsAnEmptyPage(): void
    {
        $this->change(['show_startpage' => false]);

        $response = $this->request('GET', '/', auth: false);
        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(200, $this->request('HEAD', '/', auth: false)->status);

        $this->change(['show_startpage' => true]);
        self::assertStringContainsString('Links that rot.', $this->request('GET', '/', auth: false)->body);
    }

    public function testTitleOffRemovesTheBrandFromEveryPage(): void
    {
        $id = $this->upload('0123456789', 'a.txt');
        $this->change(['show_title' => false]);

        foreach (["/file/$id/a.txt", '/', '/file/nope'] as $path) {
            $html = $this->request('GET', $path, auth: false)->body;
            self::assertStringNotContainsString('class="brand"', $html, $path);
            self::assertStringNotContainsString('class="chain"', $html, $path);
            self::assertStringNotContainsString('link<span class="rot">rot</span>', $html, $path);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function lines(): iterable
    {
        yield 'linkrot' => ['show_linkrot_line', 'id="c-cli"'];
        yield 'curl' => ['show_curl_line', 'id="c-curl"'];
        yield 'hash' => ['show_hash_line', 'id="c-sha"'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lines')]
    public function testEachLineHasItsOwnToggle(string $key, string $marker): void
    {
        $this->change([$key => false]);

        $html = $this->page();
        self::assertStringNotContainsString($marker, $html);
        self::assertStringContainsString('class="terminal"', $html);
        foreach (['id="c-cli"', 'id="c-curl"', 'id="c-sha"'] as $other) {
            if ($other !== $marker) {
                self::assertStringContainsString($other, $html);
            }
        }
    }

    public function testWithoutAnyLineThereIsNoTerminalSection(): void
    {
        $this->change(['show_linkrot_line' => false, 'show_curl_line' => false, 'show_hash_line' => false]);

        $html = $this->page();
        self::assertStringNotContainsString('class="terminal"', $html);
        self::assertStringNotContainsString('From a terminal', $html);
        self::assertStringContainsString('class="button" href="/dl/', $html);
    }
}
