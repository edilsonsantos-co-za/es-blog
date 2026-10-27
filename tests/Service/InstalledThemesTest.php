<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Theme\InstalledThemes;
use PHPUnit\Framework\TestCase;

final class InstalledThemesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/blog-themes-' . uniqid();
        mkdir($this->root . '/casper/templates/blog', 0777, true);
        touch($this->root . '/casper/templates/blog/layout.html.twig');
        file_put_contents($this->root . '/casper/manifest.json', '{"name":"Casper","version":"2.1.0"}');
        file_put_contents($this->root . '/casper/.installed.json', '{"signed":true}');
        // A folder without a layout is not a usable theme.
        mkdir($this->root . '/broken/templates/blog', 0777, true);
        file_put_contents($this->root . '/broken/manifest.json', '{"name":"Broken"}');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testListsAtlasAndInstalledThemes(): void
    {
        $themes = new InstalledThemes($this->root);

        self::assertSame(['atlas', 'casper'], array_column($themes->all(), 'id'));
        self::assertSame('2.1.0', $themes->get('casper')['version']);
        self::assertTrue($themes->get('casper')['signed']);
    }

    public function testTemplateDirOnlyForInstalledThemes(): void
    {
        $themes = new InstalledThemes($this->root);

        self::assertSame($this->root . '/casper/templates', $themes->templateDir('casper'));
        self::assertNull($themes->templateDir('atlas'));
        self::assertNull($themes->templateDir('broken'));
        self::assertNull($themes->templateDir('../../etc'));
    }
}
