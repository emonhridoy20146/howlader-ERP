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

class WalletBalancePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_balance_only_user_can_open_wallet_index_but_not_detail(): void
    {
        [$user, $wallet] = $this->userWithWalletPermissions(['wallet.view-balance']);

        $this->actingAs($user)
            ->get(route('wallets.index'))
            ->assertOk()
            ->assertSee('Wallet balances', false)
            ->assertSee($wallet->name, false);

        $this->actingAs($user)
            ->get(route('wallets.show', $wallet))
            ->assertForbidden();
    }

    public function test_full_wallet_view_user_can_open_wallet_detail(): void
    {
        [$user, $wallet] = $this->userWithWalletPermissions(['wallet.view-balance', 'wallet.view-any', 'wallet.view']);

        $this->actingAs($user)
            ->get(route('wallets.show', $wallet))
            ->assertOk();
    }

    public function test_balance_only_user_cannot_open_wallet_transactions_report(): void
    {
        [$user] = $this->userWithWalletPermissions(['wallet.view-balance']);

        $this->actingAs($user)
            ->get(route('reports.wallet-transactions', ['wallet_id' => 1]))
            ->assertForbidden();
    }

    /**
     * @param  list<string>  $permissionSlugs
     * @return array{0: User, 1: Wallet}
     */
    private function userWithWalletPermissions(array $permissionSlugs): array
    {
        $branch = Location::create([
            'code' => 'BR-WB',
            'name' => 'Balance branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $ledger = LedgerAccount::create([
            'code' => 'W-WB01',
            'name' => 'Balance wallet ledger',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = Wallet::create([
            'ledger_account_id' => $ledger->id,
            'code' => 'WB01',
            'name' => 'Counter cash',
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$branch->id]);

        $role = Role::query()->create([
            'slug' => 'wallet-balance-test',
            'name' => 'Wallet balance test',
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$branch->id]);

        return [$user, $wallet];
    }
}
