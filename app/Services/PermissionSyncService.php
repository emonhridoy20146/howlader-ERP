<?php

namespace App\Services;

use App\Models\Permission;
use App\Support\PermissionRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;

/**
 * Writes the catalogue in `config/permissions.php` into the `permissions` table.
 *
 * Policy reflection is no longer the source of truth; it is only used by
 * {@see self::abilitiesMissingFromCatalog()} to prove the catalogue still covers
 * every ability the code can check.
 */
class PermissionSyncService
{
    /**
     * @return array{created: int, existing: int, pruned: int, remapped: int}
     */
    public function sync(bool $pruneMissing = true): array
    {
        $slugs = collect(PermissionRegistry::slugs());
        $created = 0;
        $already = 0;

        foreach ($slugs as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => PermissionRegistry::meta($slug)['label']]
            );

            if ($permission->wasRecentlyCreated) {
                $created++;
            } else {
                $already++;
            }
        }

        $remapped = $this->remapRetiredSlugsOnRoles();

        $pruned = 0;
        if ($pruneMissing) {
            $stale = Permission::query()->whereNotIn('slug', $slugs->all())->pluck('id');
            if ($stale->isNotEmpty()) {
                Permission::query()->whereIn('id', $stale->all())->delete();
                $pruned = $stale->count();
            }
        }

        return ['created' => $created, 'existing' => $already, 'pruned' => $pruned, 'remapped' => $remapped];
    }

    /**
     * Kept for callers (and tests) written against the previous reflection-based API.
     *
     * @return array{created: int, existing: int, pruned: int, remapped: int}
     */
    public function syncFromPolicies(bool $pruneMissing = true): array
    {
        return $this->sync($pruneMissing);
    }

    /**
     * One cheap query on the hot path; the real sync runs in migrations, the
     * seeder and `php artisan permissions:sync`.
     */
    public function syncIfStale(): void
    {
        if (Permission::query()->count() === count(PermissionRegistry::slugs())) {
            return;
        }

        $this->sync();
    }

    /**
     * Give every role holding a retired slug the canonical slugs that replaced it.
     *
     * @return int number of role/permission rows added
     */
    public function remapRetiredSlugsOnRoles(): int
    {
        $retired = PermissionRegistry::retired();
        if ($retired === []) {
            return 0;
        }

        $retiredIds = Permission::query()->whereIn('slug', $retired)->pluck('slug', 'id');
        if ($retiredIds->isEmpty()) {
            return 0;
        }

        $canonicalIds = Permission::query()
            ->whereIn('slug', PermissionRegistry::slugs())
            ->pluck('id', 'slug');

        $added = 0;

        $rows = DB::table('permission_role')
            ->whereIn('permission_id', $retiredIds->keys()->all())
            ->get();

        foreach ($rows->groupBy('role_id') as $roleId => $roleRows) {
            $oldSlugs = $roleRows->map(fn ($row) => $retiredIds[$row->permission_id] ?? null)->filter()->all();
            $replacements = PermissionRegistry::canonicalise($oldSlugs);

            foreach ($replacements as $slug) {
                $permissionId = $canonicalIds[$slug] ?? null;
                if ($permissionId === null) {
                    continue;
                }

                $exists = DB::table('permission_role')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $exists) {
                    DB::table('permission_role')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                    $added++;
                }
            }
        }

        return $added;
    }

    /**
     * Policy abilities whose slug is not declared in the catalogue. A non-empty
     * result means someone added an ability that no role can ever be granted.
     *
     * @return list<string>
     */
    public function abilitiesMissingFromCatalog(): array
    {
        $retired = array_flip(PermissionRegistry::retired());

        return $this->policyPermissionSlugs()
            ->reject(fn (string $slug) => PermissionRegistry::has($slug) || isset($retired[$slug]))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, string>
     */
    public function policyPermissionSlugs(): Collection
    {
        $files = File::exists(app_path('Policies'))
            ? File::files(app_path('Policies'))
            : [];

        return collect($files)
            ->filter(fn ($file) => str_ends_with($file->getFilename(), 'Policy.php'))
            ->reject(fn ($file) => in_array($file->getBasename('.php'), ['BaseModelPolicy'], true))
            ->flatMap(function ($file): array {
                $class = 'App\\Policies\\'.$file->getBasename('.php');
                if (! class_exists($class)) {
                    return [];
                }

                $reflection = new ReflectionClass($class);
                $base = Str::kebab(str_replace('Policy', '', $reflection->getShortName()));
                $customMap = $this->customPermissionMap($reflection->getShortName());

                return collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
                    ->reject(fn (ReflectionMethod $method) => in_array($method->getName(), ['__construct', 'before'], true))
                    ->reject(fn (ReflectionMethod $method) => $method->isStatic())
                    ->map(function (ReflectionMethod $method) use ($base, $customMap): string {
                        if (isset($customMap[$method->getName()])) {
                            return $customMap[$method->getName()];
                        }

                        return $base.'.'.Str::of($method->getName())->snake('-');
                    })
                    ->values()
                    ->all();
            })
            ->unique()
            ->values();
    }

    /**
     * Abilities whose permission slug does not follow `{model}.{ability}`.
     *
     * @return array<string, string>
     */
    private function customPermissionMap(string $policyClassName): array
    {
        return match ($policyClassName) {
            'SystemPolicy' => [
                'viewAllLocations' => 'location.view-all',
                'approvePriceOverride' => 'pricing.override.approve',
                'applyDiscount' => 'sales.discount.apply',
                'overrideMaximumDiscount' => 'sales.maximum-discount.override',
                'applySettlementDiscount' => 'sales.settlement-discount.apply',
                'collectFlexiblePayment' => 'payment.collect-flexible',
                'writeOffDebt' => 'debt.write-off',
                'removeDebtWriteoff' => 'debt.write-off.remove',
                'backdateSalesInvoice' => 'sales.invoice.backdate',
                'viewLineProfit' => 'sales.invoice.view-line-profit',
                'viewProductPurchasePrice' => 'product.view-purchase-price',
                'updatePaymentAllocation' => 'sales.payment-allocation.update',
                'deletePaymentAllocation' => 'sales.payment-allocation.delete',
                'manageOwnerCapitalWallet' => 'wallet.owner-capital.manage',
                'manageCompanyPadSettings' => 'settings.company-pad.manage',
                'viewDashboardAttendance' => 'dashboard.attendance.view',
                'viewDashboardTargets' => 'dashboard.targets.view',
                'viewReports' => 'reports.view',
                'exportReports' => 'reports.export',
            ],
            'ProductPolicy' => [
                'manageStockLevels' => 'inventory.stock-manage',
                'globalSearchView' => 'product.global-search-view',
                'viewPurchasePrice' => 'product.view-purchase-price',
            ],
            'CustomerPolicy' => [
                'viewAnyTracking' => 'customer.tracking.view',
                'viewTracking' => 'customer.tracking.view',
                'updateTracking' => 'customer.tracking.update',
            ],
            'SalesInvoicePolicy' => [
                'backdate' => 'sales.invoice.backdate',
                'viewLineProfit' => 'sales.invoice.view-line-profit',
            ],
            'JournalEntryPolicy' => [
                'createWalletOpeningBalance' => 'journal-entry.create-opening-balance',
                'deletePosted' => 'journal-entry.delete-posted',
            ],
            'RolePolicy' => [
                'assignPermissions' => 'role.assign-permissions',
            ],
            default => [],
        };
    }
}
