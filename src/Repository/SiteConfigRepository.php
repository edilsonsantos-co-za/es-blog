<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SiteConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SiteConfig>
 */
class SiteConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteConfig::class);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $config = $this->findOneBy(['configKey' => $key]);

        return $config?->getConfigValue() ?? $default;
    }

    public function set(string $key, ?string $value): void
    {
        $config = $this->findOneBy(['configKey' => $key]);
        if ($config === null) {
            $config = new SiteConfig();
            $config->setConfigKey($key);
            $this->getEntityManager()->persist($config);
        }
        $config->setConfigValue($value);
        $this->getEntityManager()->flush();
    }

    /** @return array<string, string|null> all config as key-value map */
    public function getAll(): array
    {
        $rows = $this->findAll();
        $map = [];
        foreach ($rows as $row) {
            $map[$row->getConfigKey()] = $row->getConfigValue();
        }

        return $map;
    }
}
