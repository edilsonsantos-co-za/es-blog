<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AppVersion;
use PHPUnit\Framework\TestCase;

final class AppVersionTest extends TestCase
{
    public function testItReadsVersionFromVersionFile(): void
    {
        $projectDir = sys_get_temp_dir() . '/app-version-test-' . bin2hex(random_bytes(6));
        mkdir($projectDir, 0777, true);
        file_put_contents($projectDir . '/VERSION', "1.2.3\n");

        $service = new AppVersion($projectDir);

        self::assertSame('1.2.3', $service->getVersion());
        self::assertSame('v1.2.3', $service->getReleaseTag());
    }

    public function testItFailsWhenVersionFileIsMissing(): void
    {
        $projectDir = sys_get_temp_dir() . '/app-version-test-missing-' . bin2hex(random_bytes(6));
        mkdir($projectDir, 0777, true);

        $service = new AppVersion($projectDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read project version');

        $service->getVersion();
    }
}
