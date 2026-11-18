<?php

namespace App\Providers;

use App\Jobs\RefreshCustomerRiskSnapshot;
use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\InstallmentWriteoffAllocation;
use App\Models\InvoiceDebtWriteoff;
use App\Models\Location;
use App\Models\Payment;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Services\PermissionSyncService;
use App\Support\InventoryLocationContext;
use App\Support\PermissionRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(InventoryLocationContext::class, fn ($app) => new InventoryLocationContext($app->make('session.store')));
        $this->app->singleton(DocumentNumberGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            if (Schema::hasTable('permissions')) {
                app(PermissionSyncService::class)->syncIfStale();
            }
        } catch (QueryException) {
            // Console commands such as config:clear must still run without a database.
        }

        $this->registerModelPolicies();
        $this->registerRiskSnapshotObservers();

        View::composer('layouts.app', function ($view): void {
            $context = app(InventoryLocationContext::class);
            $user = auth()->user();
            $query = Location::query()->where('is_active', true)->orderBy('name');
            if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
                $query->whereIn('id', $user->accessibleLocationIds());
            }

            $showNavLocationAllOption = ! $user || $user->isAdmin() || $user->hasPermission('location.view-all');

            $navContextLocations = $query->get(['id', 'code', 'name']);
            if (! $showNavLocationAllOption && $context->currentId() === null && $navContextLocations->count() === 1) {
                $context->set((int) $navContextLocations->first()->id);
            }

            $navContextLocationId = $context->currentId();
            $navContextLocation = $navContextLocationId !== null
                ? $navContextLocations->firstWhere('id', $navContextLocationId)
                : null;

            $view->with([
                'navContextLocations' => $navContextLocations,
                'navContextLocationId' => $navContextLocationId,
                'navContextLocation' => $navContextLocation,
                'showNavLocationAllOption' => $showNavLocationAllOption,
            ]);
        });

        $this->registerGates();
    }

    /**
     * Readable gate names for the permission slugs that are not plain model abilities.
     * Every gate maps to exactly one slug in `config/permissions.php`.
     */
    /** Keep customer_risk_snapshots fresh whenever money or installment rows change. */
    private function registerRiskSnapshotObservers(): void
    {
        $dispatch = function (callable $resolveCustomerId): \Closure {
            return function ($model) use ($resolveCustomerId): void {
                $customerId = $resolveCustomerId($model);
                if ($customerId) {
                    RefreshCustomerRiskSnapshot::dispatch((int) $customerId);
                }
            };
        };

        $map = [
            Payment::class => fn ($m) => $m->customer_id,
            InstallmentPlan::class => fn ($m) => $m->customer_id,
            InstallmentSchedule::class => fn ($m) => $m->installmentPlan?->customer_id,
            InstallmentPaymentAllocation::class => fn ($m) => $m->installmentSchedule?->installmentPlan?->customer_id,
            InstallmentWriteoffAllocation::class => fn ($m) => $m->installmentSchedule?->installmentPlan?->customer_id,
            InvoiceDebtWriteoff::class => fn ($m) => $m->salesInvoice?->customer_id,
        ];

        foreach ($map as $modelClass => $resolver) {
            $listener = $dispatch($resolver);
            $modelClass::saved($listener);
            $modelClass::deleted($listener);
        }
    }

    private function registerGates(): void
    {
        $bypassExceptions = config('permissions.admin_bypass_exceptions', []);

        Gate::before(function ($user, string $ability) use ($bypassExceptions): ?bool {
            if (! $user instanceof User || ! $user->isAdmin()) {
                return null;
            }

            if (in_array($ability, $bypassExceptions, true)) {
                return null;
            }

            return true;
        });

        $gates = [
            'view-all-branches' => 'location.view-all',
            'approve-transfers' => 'stock-transfer.approve',
            'post-goods-receipt' => 'goods-receipt.post',
            'approve-returns' => 'return-order.approve',
            'approve-price-override' => 'pricing.override.approve',
            'apply-discount' => 'sales.discount.apply',
            'override-maximum-discount' => 'sales.maximum-discount.override',
            'apply-settlement-discount' => 'sales.settlement-discount.apply',
            'collect-flexible-payment' => 'payment.collect-flexible',
            'reschedule-installment' => 'installment-plan.reschedule',
            'allow-same-day-duplicate-installment' => 'installment.allow-same-day-duplicate',
            'write-off-debt' => 'debt.write-off',
            'remove-debt-writeoff' => 'debt.write-off.remove',
            'backdate-sales-invoice' => 'sales.invoice.backdate',
            'view-line-profit' => 'sales.invoice.view-line-profit',
            'view-product-purchase-price' => 'product.view-purchase-price',
            'delete-payment-allocation' => 'sales.payment-allocation.delete',
            'update-payment-method' => 'sales.payment-allocation.update',
            'manage-owner-capital-wallet' => 'wallet.owner-capital.manage',
            'delete-posted-journal-entry' => 'journal-entry.delete-posted',
            'manage-company-pad-settings' => 'settings.company-pad.manage',
            'view-dashboard-attendance' => 'dashboard.attendance.view',
            'view-dashboard-targets' => 'dashboard.targets.view',
            'view-reports' => 'reports.view',
            'export-reports' => 'reports.export',
            'view-customer-risk' => 'reports.customer_risk.view',
            'view-yearly-collection' => 'reports.yearly_collection.view',
        ];

        foreach ($gates as $gate => $slug) {
            Gate::define($gate, fn (User $user) => $user->hasPermission($slug));
        }

        /** Report subjects are split by topic; the slug resolves through the catalogue. */
        Gate::define(
            'view-report',
            fn (User $user, string $reportSlug) => $user->hasPermission(PermissionRegistry::reportPermission($reportSlug))
        );
    }

    private function registerModelPolicies(): void
    {
        $modelsPath = app_path('Models');
        $policiesPath = app_path('Policies');
        if (! File::isDirectory($modelsPath) || ! File::isDirectory($policiesPath)) {
            return;
        }

        foreach (File::files($modelsPath) as $file) {
            $model = $file->getBasename('.php');
            $policyClass = 'App\\Policies\\'.$model.'Policy';
            $modelClass = 'App\\Models\\'.$model;

            if (class_exists($modelClass) && class_exists($policyClass)) {
                Gate::policy($modelClass, $policyClass);
            }
        }
    }
}
