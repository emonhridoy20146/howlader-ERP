<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Read access to the permission catalogue declared in `config/permissions.php`.
 */
final class PermissionRegistry
{
    public const DEFAULT_REPORT_PERMISSION = 'reports.view';

    /**
     * @return array<string, array{label: string, description: string, groups: array<string, array{label: string, permissions: array<string, array<string, mixed>>}>}>
     */
    public static function modules(): array
    {
        return config('permissions.modules', []);
    }

    /**
     * Every canonical slug, in catalogue order.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        $slugs = [];
        foreach (self::modules() as $module) {
            foreach ($module['groups'] ?? [] as $group) {
                foreach (array_keys($group['permissions'] ?? []) as $slug) {
                    $slugs[] = $slug;
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    public static function has(string $slug): bool
    {
        return in_array($slug, self::slugs(), true);
    }

    /**
     * @return array{label: string, description: string, sensitive: bool}
     */
    public static function meta(string $slug): array
    {
        foreach (self::modules() as $module) {
            foreach ($module['groups'] ?? [] as $group) {
                if (isset($group['permissions'][$slug])) {
                    $row = $group['permissions'][$slug];

                    return [
                        'label' => $row['label'] ?? self::fallbackLabel($slug),
                        'description' => $row['description'] ?? '',
                        'sensitive' => (bool) ($row['sensitive'] ?? false),
                    ];
                }
            }
        }

        return ['label' => self::fallbackLabel($slug), 'description' => '', 'sensitive' => false];
    }

    /**
     * @return list<string>
     */
    public static function sensitiveSlugs(): array
    {
        $slugs = [];
        foreach (self::modules() as $module) {
            foreach ($module['groups'] ?? [] as $group) {
                foreach ($group['permissions'] ?? [] as $slug => $row) {
                    if ($row['sensitive'] ?? false) {
                        $slugs[] = $slug;
                    }
                }
            }
        }

        return $slugs;
    }

    /**
     * @return list<string>
     */
    public static function retired(): array
    {
        return config('permissions.retired', []);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function aliases(): array
    {
        return config('permissions.aliases', []);
    }

    /**
     * Replace retired slugs with the canonical ones they map to, and drop anything
     * that no longer exists in the catalogue.
     *
     * @param  iterable<string>  $slugs
     * @return list<string>
     */
    public static function canonicalise(iterable $slugs): array
    {
        $aliases = self::aliases();
        $known = array_flip(self::slugs());
        $out = [];

        foreach ($slugs as $slug) {
            foreach ($aliases[$slug] ?? [$slug] as $candidate) {
                if (isset($known[$candidate])) {
                    $out[$candidate] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * Permission slug that unlocks a given report route name or export slug.
     */
    public static function reportPermission(string $reportSlug): string
    {
        return config('permissions.report_subjects.'.$reportSlug, self::DEFAULT_REPORT_PERMISSION);
    }

    /**
     * @return list<string>
     */
    public static function reportPermissions(): array
    {
        return array_values(array_unique(array_merge(
            [self::DEFAULT_REPORT_PERMISSION],
            array_values(config('permissions.report_subjects', []))
        )));
    }

    private static function fallbackLabel(string $slug): string
    {
        return Str::headline(str_replace(['.', '-'], ' ', $slug));
    }
}
