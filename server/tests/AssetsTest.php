<?php

declare(strict_types=1);

namespace Linkrot\Tests;

use Linkrot\Web\Assets;
use PHPUnit\Framework\TestCase;

final class AssetsTest extends TestCase
{
    private string $manifest;

    protected function setUp(): void
    {
        $this->manifest = sys_get_temp_dir() . '/linkrot-assets-' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);
    }

    public function testPlainNamesWithoutAManifest(): void
    {
        self::assertSame('/assets/page.css', Assets::url('page.css', $this->manifest));
    }

    public function testTheManifestMapsToTheHashedName(): void
    {
        file_put_contents($this->manifest, "<?php return ['page.css' => 'page.0123456789ab.css'];\n");

        self::assertSame('/assets/page.0123456789ab.css', Assets::url('page.css', $this->manifest));
        self::assertSame('/assets/page.js', Assets::url('page.js', $this->manifest), 'a name that is not in the manifest stays');
    }

    public function testABrokenManifestIsIgnored(): void
    {
        file_put_contents($this->manifest, "<?php return 'nonsense';\n");

        self::assertSame('/assets/page.css', Assets::url('page.css', $this->manifest));
    }

    public function testTheSourceTreeHasNoManifestSoDevelopmentUsesPlainNames(): void
    {
        self::assertFileDoesNotExist(__DIR__ . '/../app/assets.php');
        self::assertSame('/assets/page.js', Assets::url('page.js'));
        self::assertFileExists(__DIR__ . '/../htdocs/assets/page.css');
        self::assertFileExists(__DIR__ . '/../htdocs/assets/page.js');
    }
}
