<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\JournalService;
use App\Services\PermissionSyncService;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class WalletTransactionsReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_wallet_transactions_are_grouped_by_location_with_running_balance(): void
    {
        [$branchA, $branchB, $wallet, $user, $expenseAccount, $incomeAccount] = $this->fixture();

        $journal = app(JournalService::class);

        $opening = $journal->postWalletOpeningBalance(
            $branchA->id,
            '2026-04-30',
            $wallet->id,
            1000,
            'Opening float',
            $user->id,
        );
        $opening->update(['status' => 'posted']);

        $income = $journal->postMultipleManualIncomes(
            $branchA->id,
            '2026-05-10',
            [['ledger_account_id' => $incomeAccount->id, 'amount' => 30, 'memo' => 'Misc']],
            $wallet->id,
            'May income',
            $user->id,
        );
        $income->update(['status' => 'posted']);

        $expense = $journal->postMultipleManualExpenses(
            $branchB->id,
            '2026-05-15',
            [['ledger_account_id' => $expenseAccount->id, 'amount' => 50, 'memo' => 'Supplies']],
            $wallet->id,
            'May expense',
            $user->id,
        );
        $expense->update(['status' => 'posted']);

        $request = Request::create('/reports/wallet-transactions', 'GET', [
            'wallet_id' => (string) $wallet->id,
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
        ]);
        $request->setUserResolver(fn () => $user);

        $payload = app(ReportDataService::class)->walletTransactionsForExport($request);

        $this->assertCount(2, $payload['summaryByBranch']);
        $this->assertCount(2, $payload['rows']);

        $branchAKey = (string) $branchA->id;
        $branchBKey = (string) $branchB->id;

        $this->assertSame(1000.0, (float) $payload['openingBalancesByBranch']->get($branchAKey));
        $this->assertSame(0.0, (float) $payload['openingBalancesByBranch']->get($branchBKey, 0));

        $branchASummary = $payload['summaryByBranch']->firstWhere('branch_id', $branchA->id);
        $branchBSummary = $payload['summaryByBranch']->firstWhere('branch_id', $branchB->id);

        $this->assertSame(1030.0, (float) $branchASummary['closing_balance']);
        $this->assertSame(-50.0, (float) $branchBSummary['closing_balance']);
        $this->assertSame(980.0, $wallet->fresh()->currentBalance());

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'wallet-transactions',
            'wallet_id' => $wallet->id,
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
        ]));

        $html->assertOk();
        $html->assertSee('Alpha branch', false);
        $html->assertSee('Zeta branch', false);
        $html->assertSee('1,030.00', false);
        $html->assertSee('-50.00', false);
    }

    public function test_wallet_transactions_can_filter_by_journal_source(): void
    {
        [$branchA, , $wallet, $user, $expenseAccount, $incomeAccount] = $this->fixture();

        $journal = app(JournalService::class);

        $opening = $journal->postWalletOpeningBalance(
            $branchA->id,
            '2026-04-30',
            $wallet->id,
            1000,
            'Opening float',
            $user->id,
        );
        $opening->update(['status' => 'posted']);

        $income = $journal->postMultipleManualIncomes(
            $branchA->id,
            '2026-05-10',
            [['ledger_account_id' => $incomeAccount->id, 'amount' => 30, 'memo' => 'Misc']],
            $wallet->id,
            'May income',
            $user->id,
        );
        $income->update(['status' => 'posted']);

        $expense = $journal->postMultipleManualExpenses(
            $branchA->id,
            '2026-05-15',
            [['ledger_account_id' => $expenseAccount->id, 'amount' => 50, 'memo' => 'Supplies']],
            $wallet->id,
            'May expense',
            $user->id,
        );
        $expense->update(['status' => 'posted']);

        $request = Request::create('/reports/wallet-transactions', 'GET', [
            'wallet_id' => (string) $wallet->id,
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'source' => 'manual_income',
        ]);

        $payload = app(ReportDataService::class)->walletTransactionsForExport($request);

        $this->assertSame(0.0, $payload['globalOpeningBalance']);
        $this->assertCount(1, $payload['rows']);
        $this->assertSame('manual_income', $payload['rows']->first()->journalEntry->source);

        $running = $payload['globalOpeningBalance'];
        foreach ($payload['rows'] as $line) {
            $running = round($running + (float) $line->debit - (float) $line->credit, 2);
        }

        $this->assertSame(30.0, $running);
    }

    /**
     * @return array{0: Location, 1: Location, 2: Wallet, 3: User, 4: LedgerAccount, 5: LedgerAccount}
     */
    private function fixture(): array
    {
        $branchA = Location::create([
            'code' => 'BR-A',
            'name' => 'Zeta branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $branchB = Location::create([
            'code' => 'BR-B',
            'name' => 'Alpha branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $walletLedger = LedgerAccount::create([
            'code' => 'W-WT-1',
            'name' => 'Report wallet ledger',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = Wallet::create([
            'ledger_account_id' => $walletLedger->id,
            'code' => 'WT01',
            'name' => 'Report wallet',
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$branchA->id, $branchB->id]);

        $expenseAccount = LedgerAccount::create([
            'code' => 'E-WT',
            'name' => 'Report expense',
            'type' => 'expense',
            'is_system' => false,
            'is_active' => true,
        ]);

        $incomeAccount = LedgerAccount::create([
            'code' => 'I-WT',
            'name' => 'Report income',
            'type' => 'income',
            'is_system' => false,
            'is_active' => true,
        ]);

        $role = Role::query()->create([
            'slug' => 'wallet-report-test',
            'name' => 'Wallet report test',
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', [
                'wallet.view',
                'wallet.view-any',
                'reports.view',
                'reports.finance.view',
                'reports.export',
            ])->pluck('id')->all(),
        );

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $branchA->id,
        ]);
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$branchA->id, $branchB->id]);

        return [$branchA, $branchB, $wallet, $user, $expenseAccount, $incomeAccount];
    }
}
