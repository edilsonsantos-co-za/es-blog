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
        mkdir($this->root . '/templates/themes/casper/blog', 0777, true);
        touch($this->root . '/templates/themes/casper/blog/layout.html.twig');
        mkdir($this->root . '/public/assets/themes/casper', 0777, true);
        file_put_contents($this->root . '/public/assets/themes/casper/theme.json', '{"name":"Casper"}');
        // A folder without a layout is not a usable theme.
        mkdir($this->root . '/templates/themes/broken/blog', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testListsAtlasAndInstalledThemes(): void
    {
        $themes = new InstalledThemes($this->root);

        self::assertSame([['id' => 'atlas', 'name' => 'Atlas'], ['id' => 'casper', 'name' => 'Casper']], $themes->all());
    }

    public function testTemplateDirOnlyForInstalledThemes(): void
    {
        $themes = new InstalledThemes($this->root);

        self::assertSame($this->root . '/templates/themes/casper', $themes->templateDir('casper'));
        self::assertNull($themes->templateDir('atlas'));
        self::assertNull($themes->templateDir('broken'));
        self::assertNull($themes->templateDir('../../etc'));
    }
}
