<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Collection;

final class CategoryTree
{
    public static function parentKey(mixed $parentId): string
    {
        if ($parentId === null || $parentId === '' || (int) $parentId === 0) {
            return 'root';
        }

        return (string) (int) $parentId;
    }

    /**
     * @return Collection<int, Category>
     */
    public static function ordered(): Collection
    {
        return Category::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<array{id: int, name: string, parent_id: ?int, depth: int, product_count: int}>
     */
    public static function flatNodes(?Collection $categories = null, bool $includeDescendantProductCounts = false): array
    {
        $categories ??= self::ordered();
        $byParent = $categories->groupBy(fn (Category $c) => self::parentKey($c->parent_id));

        $nodes = [];
        $walk = function (string $parentKey, int $depth) use (&$walk, &$nodes, $byParent, $categories, $includeDescendantProductCounts): void {
            /** @var Collection<int, Category> $children */
            $children = $byParent->get($parentKey, collect());
            foreach ($children as $category) {
                $directCount = (int) ($category->products_count ?? 0);
                $productCount = $directCount;
                if ($includeDescendantProductCounts) {
                    $ids = self::selfAndDescendantIds((int) $category->id, $categories);
                    $productCount = (int) $categories
                        ->whereIn('id', $ids)
                        ->sum(fn (Category $row) => (int) ($row->products_count ?? 0));
                }

                $nodes[] = [
                    'id' => (int) $category->id,
                    'name' => $category->name,
                    'parent_id' => self::parentKey($category->parent_id) === 'root'
                        ? null
                        : (int) $category->parent_id,
                    'depth' => $depth,
                    'product_count' => $productCount,
                ];
                $walk((string) $category->id, $depth + 1);
            }
        };

        $walk('root', 0);

        return $nodes;
    }

    /**
     * @return list<array{value: string, label: string, depth: int, search: string}>
     */
    public static function selectOptions(?Collection $categories = null): array
    {
        $categories ??= self::ordered();
        $byId = $categories->keyBy('id');

        return array_map(
            function (array $node) use ($byId, $categories): array {
                $category = $byId->get($node['id']);
                $breadcrumb = $category instanceof Category
                    ? self::breadcrumb($category, $categories)
                    : $node['name'];

                return [
                    'value' => (string) $node['id'],
                    'label' => $node['name'],
                    'depth' => $node['depth'],
                    'search' => strtolower($breadcrumb.' '.$node['name']),
                ];
            },
            self::flatNodes($categories),
        );
    }

    /**
     * @return list<int>
     */
    public static function descendantIds(int $categoryId, ?Collection $categories = null): array
    {
        $categories ??= self::ordered();
        $byParent = $categories->groupBy(fn (Category $c) => self::parentKey($c->parent_id));

        $ids = [];
        $walk = function (int $parentId) use (&$walk, &$ids, $byParent): void {
            foreach ($byParent->get((string) $parentId, collect()) as $child) {
                $ids[] = (int) $child->id;
                $walk((int) $child->id);
            }
        };

        $walk($categoryId);

        return $ids;
    }

    /**
     * @return list<int>
     */
    public static function selfAndDescendantIds(int $categoryId, ?Collection $categories = null): array
    {
        return array_values(array_unique(array_merge(
            [$categoryId],
            self::descendantIds($categoryId, $categories),
        )));
    }

    public static function breadcrumb(Category $category, ?Collection $categories = null): string
    {
        $categories ??= self::ordered();
        $byId = $categories->keyBy('id');

        $parts = [$category->name];
        $parentId = $category->parent_id;
        while ($parentId && $byId->has($parentId)) {
            $parent = $byId->get($parentId);
            array_unshift($parts, $parent->name);
            $parentId = $parent->parent_id;
        }

        return implode(' › ', $parts);
    }
}
