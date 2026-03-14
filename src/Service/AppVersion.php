<?php

declare(strict_types=1);

namespace App\Service;

final class AppVersion
{
    private ?string $version = null;

    public function __construct(
        private readonly string $projectDir,
    ) {}

    public function getVersion(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $versionFile = sprintf('%s/%s', rtrim($this->projectDir, '/'), 'VERSION');
        if (!is_file($versionFile) || !is_readable($versionFile)) {
            throw new \RuntimeException(sprintf('Unable to read project version from "%s".', $versionFile));
        }

        $version = file_get_contents($versionFile);

        if ($version === false) {
            throw new \RuntimeException(sprintf('Unable to read project version from "%s".', $versionFile));
        }

        $version = trim($version);

        if ($version === '') {
            throw new \RuntimeException(sprintf('Project version file "%s" is empty.', $versionFile));
        }

        return $this->version = $version;
    }

    public function getReleaseTag(): string
    {
        return sprintf('v%s', $this->getVersion());
    }
}
