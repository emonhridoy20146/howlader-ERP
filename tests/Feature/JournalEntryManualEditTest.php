<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\JournalService;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryManualEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_user_can_update_manual_expense_wallet_and_amount_on_posted_entry(): void
    {
        [$location, $walletA, $walletB, $user, $expenseAccount] = $this->fixture(withSecondWallet: true);

        $entry = app(JournalService::class)->postMultipleManualExpenses(
            $location->id,
            '2026-05-01',
            [['ledger_account_id' => $expenseAccount->id, 'amount' => 100, 'memo' => 'Rent']],
            $walletA->id,
            'Rent payment',
            $user->id,
        );
        $entry->update(['status' => 'posted']);

        $balanceBeforeA = $walletA->fresh()->currentBalance();
        $balanceBeforeB = $walletB->fresh()->currentBalance();

        $this->actingAs($user)->put(route('journal-entries.update', $entry), [
            'entry_date' => '2026-05-02',
            'wallet_id' => $walletB->id,
            'amount' => 150,
            'description' => 'Updated rent',
        ])->assertRedirect(route('journal-entries.index'));

        $entry->refresh();
        $this->assertSame('2026-05-02', $entry->entry_date->toDateString());
        $this->assertSame('Updated rent', $entry->description);
        $this->assertSame(150.0, (float) $entry->lines->sum('debit'));
        $this->assertSame(150.0, (float) $entry->lines->sum('credit'));

        $this->assertSame(round($balanceBeforeA + 100, 2), $walletA->fresh()->currentBalance());
        $this->assertSame(round($balanceBeforeB - 150, 2), $walletB->fresh()->currentBalance());
    }

    public function test_user_can_delete_posted_manual_income_entry(): void
    {
        [$location, $wallet, $user, , , $incomeAccount] = $this->fixture(withIncomeAccount: true);

        $entry = app(JournalService::class)->postMultipleManualIncomes(
            $location->id,
            '2026-05-01',
            [['ledger_account_id' => $incomeAccount->id, 'amount' => 80, 'memo' => 'Misc']],
            $wallet->id,
            'Misc income',
            $user->id,
        );
        $entry->update(['status' => 'posted']);

        $balanceBefore = $wallet->fresh()->currentBalance();

        $this->actingAs($user)->delete(route('journal-entries.destroy', $entry))
            ->assertRedirect(route('journal-entries.index'));

        $this->assertNull(JournalEntry::query()->find($entry->id));
        $this->assertSame(round($balanceBefore - 80, 2), $wallet->fresh()->currentBalance());
    }

    public function test_system_generated_entry_cannot_be_edited(): void
    {
        [$location, $wallet, $user] = $this->fixture();

        $entry = JournalEntry::create([
            'branch_id' => $location->id,
            'entry_date' => '2026-05-01',
            'document_no' => 'JE-TEST-1',
            'description' => 'Invoice GL',
            'source' => 'sales_invoice',
            'status' => 'posted',
            'reference_type' => \App\Models\SalesInvoice::class,
            'reference_id' => 1,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->get(route('journal-entries.edit', $entry))->assertForbidden();

        $this->actingAs($user)->put(route('journal-entries.update', $entry), [
            'entry_date' => '2026-05-02',
            'wallet_id' => $wallet->id,
            'amount' => 50,
        ])->assertForbidden();
    }

    /**
     * @return array{0: Location, 1: Wallet, 2: User, 3?: Wallet, 4?: LedgerAccount, 5?: LedgerAccount}
     */
    private function fixture(
        bool $withSecondWallet = false,
        bool $withIncomeAccount = false,
    ): array {
        $location = Location::create([
            'code' => 'JE-EDIT',
            'name' => 'Edit branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $walletLedger = LedgerAccount::create([
            'code' => 'W-EDIT-1',
            'name' => 'Wallet ledger A',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $walletA = Wallet::create([
            'ledger_account_id' => $walletLedger->id,
            'code' => 'WA-EDIT',
            'name' => 'Wallet A',
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $walletA->locations()->sync([$location->id]);

        $walletB = null;
        if ($withSecondWallet) {
            $walletLedgerB = LedgerAccount::create([
                'code' => 'W-EDIT-2',
                'name' => 'Wallet ledger B',
                'type' => 'asset',
                'is_system' => false,
                'is_active' => true,
            ]);
            $walletB = Wallet::create([
                'ledger_account_id' => $walletLedgerB->id,
                'code' => 'WB-EDIT',
                'name' => 'Wallet B',
                'kind' => 'cash',
                'is_active' => true,
            ]);
            $walletB->locations()->sync([$location->id]);
        }

        $expenseAccount = LedgerAccount::create([
            'code' => 'E-EDIT',
            'name' => 'Edit expense',
            'type' => 'expense',
            'is_system' => false,
            'is_active' => true,
        ]);

        $incomeAccount = null;
        if ($withIncomeAccount) {
            $incomeAccount = LedgerAccount::create([
                'code' => 'I-EDIT',
                'name' => 'Edit income',
                'type' => 'income',
                'is_system' => false,
                'is_active' => true,
            ]);
        }

        $role = Role::query()->create([
            'slug' => 'je-edit-test',
            'name' => 'Journal edit test',
        ]);
        $ids = Permission::query()->whereIn('slug', [
            'journal-entry.view-any',
            'journal-entry.view',
            'journal-entry.update',
            'journal-entry.delete',
            'journal-entry.create-expense',
            'journal-entry.create-income',
        ])->pluck('id')->all();
        $role->permissions()->sync($ids);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$location->id]);

        if ($withSecondWallet && $withIncomeAccount) {
            return [$location, $walletA, $walletB, $user, $expenseAccount, $incomeAccount];
        }

        if ($withSecondWallet) {
            return [$location, $walletA, $walletB, $user, $expenseAccount];
        }

        if ($withIncomeAccount) {
            return [$location, $walletA, $user, null, $expenseAccount, $incomeAccount];
        }

        return [$location, $walletA, $user];
    }
}
