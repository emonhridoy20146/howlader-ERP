<?php

namespace App\Policies;

use App\Models\User;

class ProductPolicy extends BaseModelPolicy
{
    /**
     * View and maintain the stock-by-branch screen and manual adjustments (inventory_balances).
     */
    public function manageStockLevels(User $user): bool
    {
        return $user->hasPermission('inventory.stock-manage');
    }

    public function bulkUpdate(User $user): bool
    {
        return $this->allows($user, 'bulkUpdate');
    }

    /**
     * Show cost price on the product page. Employees without this see sale price
     * and the discount limit only.
     */
    public function viewPurchasePrice(User $user): bool
    {
        return $user->hasPermission('product.view-purchase-price')
            || $user->hasPermission('sales.invoice.view-line-profit');
    }

    /**
     * Find products in global search and open the product detail page view-only,
     * including inventory at every location (without catalog index / edit access).
     */
    public function globalSearchView(User $user): bool
    {
        return $user->hasPermission('product.global-search-view');
    }
}
