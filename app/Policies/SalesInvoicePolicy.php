<?php

namespace App\Policies;

use App\Models\SalesInvoice;
use App\Models\User;

class SalesInvoicePolicy extends BaseModelPolicy
{
    /**
     * `changeSaleType` and `reviseActive` guard the document state as well as the
     * permission, so the admin shortcut must not skip them.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, ['changeSaleType', 'reviseActive'], true)) {
            return null;
        }

        return parent::before($user, $ability);
    }

    public function post(User $user): bool
    {
        return $this->allows($user, 'post');
    }

    public function print(User $user): bool
    {
        return $this->allows($user, 'print');
    }

    public function activateInstallment(User $user): bool
    {
        return $this->allows($user, 'activate-installment');
    }

    public function reviseActive(User $user, SalesInvoice $invoice): bool
    {
        if (! in_array($invoice->status, ['posted', 'active'], true)) {
            return false;
        }

        return $user->isAdmin() || $user->hasPermission('sales-invoice.revise-active');
    }

    public function changeSaleType(User $user, SalesInvoice $invoice): bool
    {
        if (! in_array($invoice->status, ['posted', 'active'], true) || $invoice->sale_type === 'installment') {
            return false;
        }

        return $user->isAdmin() || $user->hasPermission('sales-invoice.change-sale-type');
    }

    public function viewTrashed(User $user): bool
    {
        return $this->allows($user, 'view-trashed');
    }

    public function restore(User $user, SalesInvoice $invoice): bool
    {
        return $this->allows($user, 'restore');
    }

    public function forceDelete(User $user, SalesInvoice $invoice): bool
    {
        return $this->allows($user, 'force-delete');
    }
}
