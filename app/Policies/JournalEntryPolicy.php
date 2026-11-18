<?php

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class JournalEntryPolicy extends BaseModelPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isAdmin()) {
            if (in_array($ability, ['update', 'delete'], true)) {
                return null;
            }

            return true;
        }

        return null;
    }

    public function view(User $user, Model $model): bool
    {
        return $this->inBranch($user, $model) && parent::view($user, $model);
    }

    public function create(User $user): bool
    {
        return $this->createExpense($user)
            || $this->createIncome($user)
            || $this->createTransfer($user)
            || $this->createWalletOpeningBalance($user);
    }

    public function createExpense(User $user): bool
    {
        return $user->hasPermission('journal-entry.create-expense');
    }

    public function createIncome(User $user): bool
    {
        return $user->hasPermission('journal-entry.create-income');
    }

    public function createTransfer(User $user): bool
    {
        return $user->hasPermission('journal-entry.create-transfer');
    }

    public function createWalletOpeningBalance(User $user): bool
    {
        return $user->hasPermission('journal-entry.create-opening-balance');
    }

    public function deletePosted(User $user): bool
    {
        return $user->hasPermission('journal-entry.delete-posted');
    }

    public function post(User $user, JournalEntry $model): bool
    {
        return ($user->isAdmin() || $user->hasPermission('journal-entry.post')) && $this->inBranch($user, $model);
    }

    public function update(User $user, Model $model): bool
    {
        if (! $model instanceof JournalEntry || ! $model->isEditableManualEntry()) {
            return false;
        }

        return $this->inBranch($user, $model) && parent::update($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        if (! $model instanceof JournalEntry) {
            return false;
        }

        if ($model->isEditableManualEntry()) {
            return $this->inBranch($user, $model) && parent::delete($user, $model);
        }

        if ($model->status === 'draft') {
            return $this->inBranch($user, $model) && parent::delete($user, $model);
        }

        return $this->deletePosted($user)
            && $model->isAdminPostedJournalDeleteOnly()
            && $this->inBranch($user, $model)
            && parent::delete($user, $model);
    }

    private function inBranch(User $user, Model $model): bool
    {
        if (! $model instanceof JournalEntry) {
            return false;
        }

        return in_array((int) $model->branch_id, $user->accessibleLocationIds(), true);
    }
}
