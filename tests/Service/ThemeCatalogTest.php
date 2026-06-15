<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ThemeCatalog;
use PHPUnit\Framework\TestCase;

final class ThemeCatalogTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 2) . '/var/test-theme-catalog-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testItReadsThemeMetadata(): void
    {
        file_put_contents($this->projectDir . '/config/themes.yaml', <<<YAML
public:
  - slug: default
    name: Default
    status: active
    description: Built in theme
admin:
  - slug: light
    name: Light
YAML);

        $catalog = new ThemeCatalog($this->projectDir);

        self::assertSame('default', $catalog->getPublicThemes()[0]['slug']);
        self::assertSame('Built in theme', $catalog->getPublicThemes()[0]['description']);
        self::assertSame('light', $catalog->getAdminThemes()[0]['slug']);
    }

    public function testItAddsCurrentManualThemeWhenMissingFromCatalog(): void
    {
        file_put_contents($this->projectDir . '/config/themes.yaml', "public: []\nadmin: []\n");

        $themes = (new ThemeCatalog($this->projectDir))->getPublicThemes('custom-launch-theme');

        self::assertSame('custom-launch-theme', $themes[0]['slug']);
        self::assertSame('manual', $themes[0]['status']);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (array_diff(scandir($directory), ['.', '..']) as $item) {
            $path = $directory . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
