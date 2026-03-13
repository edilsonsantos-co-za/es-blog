<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MenuItem;
use App\Repository\MenuItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages navigation menu items.
 * Supports single-level nesting (parent → children) mirroring v1 behaviour.
 */
final class MenuService
{
    public function __construct(
        private readonly MenuItemRepository $repo,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return MenuItem[] top-level items with children pre-loaded */
    public function getMenu(): array
    {
        return $this->repo->findTopLevel();
    }

    /** @return MenuItem[] */
    public function findAll(): array
    {
        return $this->repo->findAll();
    }

    public function create(
        string $name,
        string $slug,
        ?MenuItem $parent = null,
        ?string $class = null,
        ?string $target = null,
        int $position = 0,
    ): MenuItem {
        $item = new MenuItem();
        $item->setName($name);
        $item->setSlug($slug);
        $item->setParent($parent);
        $item->setClass($class);
        $item->setTarget($target);
        $item->setPosition($position);

        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(MenuItem $item, array $data): MenuItem
    {
        if (isset($data['name']))     { $item->setName((string) $data['name']); }
        if (isset($data['slug']))     { $item->setSlug((string) $data['slug']); }
        if (isset($data['class']))    { $item->setClass((string) $data['class']); }
        if (isset($data['target']))   { $item->setTarget((string) $data['target']); }
        if (isset($data['position'])) { $item->setPosition((int) $data['position']); }
        if (array_key_exists('parent', $data)) {
            $item->setParent($data['parent'] instanceof MenuItem ? $data['parent'] : null);
        }

        $this->em->flush();

        return $item;
    }

    /**
     * Reorder items by providing an ordered array of IDs.
     *
     * @param int[] $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        foreach ($orderedIds as $position => $id) {
            $item = $this->repo->find($id);
            if ($item !== null) {
                $item->setPosition($position);
            }
        }

        $this->em->flush();
    }

    public function delete(MenuItem $item): void
    {
        $this->em->remove($item);
        $this->em->flush();
    }
}
