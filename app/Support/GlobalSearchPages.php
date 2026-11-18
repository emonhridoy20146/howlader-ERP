<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class GlobalSearchPages
{
    /**
     * @return list<array{label: string, subtitle: string, url: string}>
     */
    public static function match(string $query, ?User $user): array
    {
        $normalized = mb_strtolower(trim($query));
        if (mb_strlen($normalized) < 2 || $user === null) {
            return [];
        }

        $matches = [];

        foreach (config('global_search_pages.pages', []) as $page) {
            if (! self::userCanOpenPage($user, $page)) {
                continue;
            }

            if (! self::pageMatchesQuery($page, $normalized)) {
                continue;
            }

            $matches[] = [
                'label' => (string) $page['label'],
                'subtitle' => (string) ($page['group'] ?? 'Screen'),
                'url' => route(
                    (string) $page['route'],
                    $page['route_params'] ?? [],
                ),
            ];
        }

        return collect($matches)
            ->sortBy('label')
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private static function userCanOpenPage(User $user, array $page): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (isset($page['permission']) && is_string($page['permission'])) {
            return $user->hasPermission($page['permission']);
        }

        if (isset($page['gate']) && is_array($page['gate']) && count($page['gate']) === 2) {
            [$modelClass, $ability] = $page['gate'];

            return Gate::forUser($user)->check($ability, $modelClass);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private static function pageMatchesQuery(array $page, string $normalized): bool
    {
        $haystacks = [
            mb_strtolower((string) $page['label']),
            mb_strtolower((string) ($page['group'] ?? '')),
        ];

        foreach ($page['keywords'] ?? [] as $keyword) {
            $haystacks[] = mb_strtolower((string) $keyword);
        }

        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && str_contains($haystack, $normalized)) {
                return true;
            }
        }

        return false;
    }
}
