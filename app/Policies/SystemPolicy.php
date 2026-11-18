<?php

namespace App\Policies;

use App\Models\User;

/**
 * Abilities that are not attached to a single Eloquent model.
 *
 * Each method checks exactly one permission slug; nothing here bundles several
 * capabilities behind one check.
 */
class SystemPolicy
{
    public function viewAllLocations(User $user): bool
    {
        return $user->hasPermission('location.view-all');
    }

    public function approvePriceOverride(User $user): bool
    {
        return $user->hasPermission('pricing.override.approve');
    }

    public function applyDiscount(User $user): bool
    {
        return $user->hasPermission('sales.discount.apply');
    }

    public function overrideMaximumDiscount(User $user): bool
    {
        return $user->hasPermission('sales.maximum-discount.override');
    }

    public function applySettlementDiscount(User $user): bool
    {
        return $user->hasPermission('sales.settlement-discount.apply');
    }

    public function collectFlexiblePayment(User $user): bool
    {
        return $user->hasPermission('payment.collect-flexible');
    }

    public function writeOffDebt(User $user): bool
    {
        return $user->hasPermission('debt.write-off');
    }

    public function removeDebtWriteoff(User $user): bool
    {
        return $user->hasPermission('debt.write-off.remove');
    }

    public function backdateSalesInvoice(User $user): bool
    {
        return $user->hasPermission('sales.invoice.backdate');
    }

    public function viewLineProfit(User $user): bool
    {
        return $user->hasPermission('sales.invoice.view-line-profit');
    }

    public function viewProductPurchasePrice(User $user): bool
    {
        return $user->hasPermission('product.view-purchase-price');
    }

    public function updatePaymentAllocation(User $user): bool
    {
        return $user->hasPermission('sales.payment-allocation.update');
    }

    public function deletePaymentAllocation(User $user): bool
    {
        return $user->hasPermission('sales.payment-allocation.delete');
    }

    public function manageOwnerCapitalWallet(User $user): bool
    {
        return $user->hasPermission('wallet.owner-capital.manage');
    }

    public function manageCompanyPadSettings(User $user): bool
    {
        return $user->hasPermission('settings.company-pad.manage');
    }

    public function viewDashboardAttendance(User $user): bool
    {
        return $user->hasPermission('dashboard.attendance.view');
    }

    public function viewDashboardTargets(User $user): bool
    {
        return $user->hasPermission('dashboard.targets.view');
    }

    public function viewReports(User $user): bool
    {
        return $user->hasPermission('reports.view');
    }

    public function exportReports(User $user): bool
    {
        return $user->hasPermission('reports.export');
    }
}
