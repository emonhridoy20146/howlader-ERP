<?php

namespace App\Support;

use App\Models\Permission;
use Illuminate\Support\Collection;

/**
 * Turns the catalogue plus the `permissions` table into the rows the role editor
 * renders. Anything present in the database but absent from the catalogue is
 * surfaced under "Unmapped" rather than hidden, so a role never carries a
 * permission the editor cannot show or remove.
 */
final class RolePermissionCatalog
{
    /**
     * @return Collection<int, array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     groups: Collection<int, array{key: string, label: string, permissions: Collection<int, array<string, mixed>>}>
     * }>
     */
    public static function forEditor(): Collection
    {
        $idsBySlug = Permission::query()->pluck('id', 'slug');
        $modules = collect();

        foreach (PermissionRegistry::modules() as $moduleKey => $module) {
            $groups = collect();

            foreach ($module['groups'] ?? [] as $groupKey => $group) {
                $rows = collect();

                foreach ($group['permissions'] ?? [] as $slug => $meta) {
                    $id = $idsBySlug[$slug] ?? null;
                    if ($id === null) {
                        continue;
                    }

                    $rows->push([
                        'id' => (int) $id,
                        'slug' => $slug,
                        'label' => $meta['label'] ?? $slug,
                        'description' => $meta['description'] ?? '',
                        'sensitive' => (bool) ($meta['sensitive'] ?? false),
                    ]);
                }

                if ($rows->isNotEmpty()) {
                    $groups->push([
                        'key' => (string) $groupKey,
                        'label' => $group['label'] ?? $groupKey,
                        'permissions' => $rows,
                    ]);
                }
            }

            if ($groups->isNotEmpty()) {
                $modules->push([
                    'key' => (string) $moduleKey,
                    'label' => $module['label'] ?? $moduleKey,
                    'description' => $module['description'] ?? '',
                    'groups' => $groups,
                ]);
            }
        }

        $unmapped = self::unmappedRows($idsBySlug);
        if ($unmapped->isNotEmpty()) {
            $modules->push([
                'key' => 'unmapped',
                'label' => 'Unmapped',
                'description' => 'Permission rows that are not declared in config/permissions.php. Run `php artisan permissions:sync` to clear them.',
                'groups' => collect([[
                    'key' => 'unmapped',
                    'label' => 'Undeclared permissions',
                    'permissions' => $unmapped,
                ]]),
            ]);
        }

        return $modules;
    }

    /**
     * @param  Collection<string, int>  $idsBySlug
     * @return Collection<int, array<string, mixed>>
     */
    private static function unmappedRows(Collection $idsBySlug): Collection
    {
        $known = array_flip(PermissionRegistry::slugs());

        return $idsBySlug
            ->reject(fn (int $id, string $slug) => isset($known[$slug]))
            ->map(fn (int $id, string $slug) => [
                'id' => $id,
                'slug' => $slug,
                'label' => $slug,
                'description' => 'Not declared in the catalogue.',
                'sensitive' => false,
            ])
            ->values();
    }
}
