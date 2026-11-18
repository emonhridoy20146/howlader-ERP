<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PermissionSyncService;
use App\Support\OwnerCapitalWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerCapitalWalletJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_non_admin_cannot_expense_from_owner_wallet(): void
    {
        [$location, $ownerWallet, $otherWallet, $user, $expenseAccount] = $this->fixture(false);

        $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'expense',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-01',
            'wallet_id' => $ownerWallet->id,
            'expenses' => [
                ['ledger_account_id' => $expenseAccount->id, 'amount' => 10, 'memo' => 'Test'],
            ],
        ])->assertRedirect()->assertSessionHasErrors('wallet_id');

        $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'expense',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-01',
            'wallet_id' => $otherWallet->id,
            'expenses' => [
                ['ledger_account_id' => $expenseAccount->id, 'amount' => 10, 'memo' => 'Test'],
            ],
        ])->assertRedirect();
    }

    public function test_non_admin_cannot_post_opening_balance_to_owner_wallet(): void
    {
        [$location, $ownerWallet, , $user] = $this->fixture(false);

        $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'wallet_opening',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-01',
            'wallet_id' => $ownerWallet->id,
            'amount' => 100,
        ])->assertRedirect()->assertSessionHasErrors('wallet_id');
    }

    public function test_non_admin_can_transfer_into_owner_wallet_but_not_out(): void
    {
        [$location, $ownerWallet, $otherWallet, $user] = $this->fixture(false);

        $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'transfer',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-01',
            'from_wallet_id' => $ownerWallet->id,
            'to_wallet_id' => $otherWallet->id,
            'amount' => 25,
        ])->assertRedirect()->assertSessionHasErrors('from_wallet_id');

        $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'transfer',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-01',
            'from_wallet_id' => $otherWallet->id,
            'to_wallet_id' => $ownerWallet->id,
            'amount' => 25,
        ])->assertRedirect();

        $this->assertDatabaseHas('journal_entries', [
            'branch_id' => $location->id,
            'source' => 'manual_transfer',
        ]);
    }

    public function test_admin_can_expense_and_open_owner_wallet(): void
    {
        [$location, $ownerWallet, , $user, $expenseAccount] = $this->fixture(true);

        $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'expense',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-01',
            'wallet_id' => $ownerWallet->id,
            'expenses' => [
                ['ledger_account_id' => $expenseAccount->id, 'amount' => 10, 'memo' => 'Owner spend'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('journal_entries', [
            'source' => 'manual_expense',
        ]);

        $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'wallet_opening',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-02',
            'wallet_id' => $ownerWallet->id,
            'amount' => 500,
        ])->assertRedirect();

        $this->assertTrue(
            JournalEntry::query()->where('source', 'wallet_opening_balance')->exists()
        );
    }

    /**
     * @return array{0: Location, 1: Wallet, 2: Wallet, 3: User, 4?: LedgerAccount}
     */
    private function fixture(bool $asAdmin): array
    {
        $location = Location::create([
            'code' => 'OWN-JE',
            'name' => 'Owner wallet branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $ownerWallet = $this->makeWallet($location, OwnerCapitalWallet::code(), 'Owner capital');
        $otherWallet = $this->makeWallet($location, 'CASH-001', 'Branch cash');

        $expenseAccount = LedgerAccount::create([
            'code' => 'E-OWN-'.random_int(100, 999),
            'name' => 'Test expense',
            'type' => 'expense',
            'is_system' => false,
            'is_active' => true,
        ]);

        if ($asAdmin) {
            $user = User::factory()->create([
                'role' => 'admin',
                'is_active' => true,
                'branch_id' => $location->id,
            ]);
            $user->accessibleLocations()->sync([$location->id]);

            return [$location, $ownerWallet, $otherWallet, $user, $expenseAccount];
        }

        $permissionSlugs = [
            'journal-entry.create-expense',
            'journal-entry.create-transfer',
            'journal-entry.create-opening-balance',
            'journal-entry.view-any',
            'journal-entry.view',
        ];
        $role = Role::query()->create([
            'slug' => 'owner-wallet-test-'.random_int(1000, 9999),
            'name' => 'Owner wallet test',
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id')->all()
        );

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$location->id]);

        return [$location, $ownerWallet, $otherWallet, $user, $expenseAccount];
    }

    private function makeWallet(Location $location, string $code, string $name): Wallet
    {
        $ledger = LedgerAccount::create([
            'code' => 'L-'.$code,
            'name' => $name.' ledger',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = Wallet::create([
            'ledger_account_id' => $ledger->id,
            'code' => $code,
            'name' => $name,
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$location->id]);

        return $wallet;
    }
}
