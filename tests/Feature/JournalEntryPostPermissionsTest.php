<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryPostPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_each_entry_kind_needs_its_own_permission(): void
    {
        [$location, $wallet, $user] = $this->fixtureUserWithPermissions([
            'journal-entry.create-opening-balance',
            'journal-entry.create-transfer',
            'journal-entry.create-income',
        ]);

        $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'wallet_opening',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-01',
            'wallet_id' => $wallet->id,
            'amount' => 100,
        ])->assertRedirect();

        $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'transfer',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-01',
            'from_wallet_id' => $wallet->id,
            'to_wallet_id' => $this->secondWallet($location)->id,
            'amount' => 10,
        ])->assertRedirect();

        $incomeAccount = LedgerAccount::create([
            'code' => 'I-JE-LEG-'.random_int(100, 999),
            'name' => 'Legacy income',
            'type' => 'income',
            'is_system' => false,
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'income',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-01',
            'wallet_id' => $wallet->id,
            'income_lines' => [
                ['ledger_account_id' => $incomeAccount->id, 'amount' => 15, 'memo' => 'Misc'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('journal_entries', ['source' => 'manual_income']);
    }

    public function test_opening_balance_permission_is_required_for_opening_post(): void
    {
        [$location, $wallet, $user] = $this->fixtureUserWithPermissions(['journal-entry.create-expense']);

        $response = $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'wallet_opening',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-01',
            'wallet_id' => $wallet->id,
            'amount' => 100,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('entry_kind');
        $this->assertDatabaseMissing('journal_entries', ['source' => 'wallet_opening_balance']);
    }

    public function test_expense_permission_allows_expense_post(): void
    {
        [$location, $wallet, $user, $expenseAccount] = $this->fixtureUserWithPermissions(['journal-entry.create-expense'], withExpenseAccount: true);

        $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'expense',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-01',
            'wallet_id' => $wallet->id,
            'expenses' => [
                ['ledger_account_id' => $expenseAccount->id, 'amount' => 25, 'memo' => 'Stationery'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('journal_entries', [
            'branch_id' => $location->id,
            'source' => 'manual_expense',
        ]);
    }

    public function test_income_permission_allows_income_post(): void
    {
        [$location, $wallet, $user, , $incomeAccount] = $this->fixtureUserWithPermissions(
            ['journal-entry.create-income'],
            withExpenseAccount: true,
            withIncomeAccount: true,
        );

        $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'income',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-01',
            'wallet_id' => $wallet->id,
            'income_lines' => [
                ['ledger_account_id' => $incomeAccount->id, 'amount' => 50, 'memo' => 'Scrap sale'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('journal_entries', [
            'branch_id' => $location->id,
            'source' => 'manual_income',
        ]);
    }

    public function test_income_permission_is_required_for_income_post(): void
    {
        [$location, $wallet, $user, , $incomeAccount] = $this->fixtureUserWithPermissions(
            ['journal-entry.create-expense'],
            withExpenseAccount: true,
            withIncomeAccount: true,
        );

        $response = $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'income',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-01',
            'wallet_id' => $wallet->id,
            'income_lines' => [
                ['ledger_account_id' => $incomeAccount->id, 'amount' => 50, 'memo' => 'Scrap sale'],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('entry_kind');
        $this->assertDatabaseMissing('journal_entries', ['source' => 'manual_income']);
    }

    public function test_create_page_forbidden_without_any_post_permission(): void
    {
        [$location, , $user] = $this->fixtureUserWithPermissions(['journal-entry.view-any', 'journal-entry.view']);

        $this->actingAs($user)->get(route('journal-entries.create'))->assertForbidden();
    }

    /**
     * @param  list<string>  $permissionSlugs
     * @return array{0: Location, 1: Wallet, 2: User, 3?: LedgerAccount}
     */
    private function fixtureUserWithPermissions(
        array $permissionSlugs,
        bool $withExpenseAccount = false,
        bool $withIncomeAccount = false,
    ): array {
        $location = Location::create([
            'code' => 'JE-'.substr(md5(json_encode($permissionSlugs)), 0, 6),
            'name' => 'Journal branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $ledger = LedgerAccount::create([
            'code' => 'W-JE-'.random_int(100, 999),
            'name' => 'Journal wallet ledger',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = Wallet::create([
            'ledger_account_id' => $ledger->id,
            'code' => 'JW-'.random_int(100, 999),
            'name' => 'Journal wallet',
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$location->id]);

        $role = Role::query()->create([
            'slug' => 'je-test-'.substr(md5(json_encode($permissionSlugs)), 0, 8),
            'name' => 'Journal test role',
        ]);
        $ids = Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$location->id]);

        $expenseAccount = null;
        if ($withExpenseAccount) {
            $expenseAccount = LedgerAccount::create([
                'code' => 'E-JE-'.random_int(100, 999),
                'name' => 'Test expense',
                'type' => 'expense',
                'is_system' => false,
                'is_active' => true,
            ]);
        }

        $incomeAccount = null;
        if ($withIncomeAccount) {
            $incomeAccount = LedgerAccount::create([
                'code' => 'I-JE-'.random_int(100, 999),
                'name' => 'Test income',
                'type' => 'income',
                'is_system' => false,
                'is_active' => true,
            ]);
        }

        if ($withExpenseAccount && $withIncomeAccount) {
            return [$location, $wallet, $user, $expenseAccount, $incomeAccount];
        }

        if ($withExpenseAccount) {
            return [$location, $wallet, $user, $expenseAccount];
        }

        if ($withIncomeAccount) {
            return [$location, $wallet, $user, null, $incomeAccount];
        }

        return [$location, $wallet, $user];
    }

    private function secondWallet(Location $location): Wallet
    {
        $ledger = LedgerAccount::create([
            'code' => 'W-JE2-'.random_int(100, 999),
            'name' => 'Second wallet ledger',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = Wallet::create([
            'ledger_account_id' => $ledger->id,
            'code' => 'JW2-'.random_int(100, 999),
            'name' => 'Second wallet',
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$location->id]);

        return $wallet;
    }
}
