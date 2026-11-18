<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class FinancialReportBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_profit_loss_sheet_scopes_by_branch_or_all_branches(): void
    {
        [$user, $branchA, $branchB, $expenseAccount] = $this->seedBranchesWithFinancialActivity();

        $service = app(ReportDataService::class);
        $mayFilters = [
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'branch_id' => '',
        ];

        $allBranches = $service->profitLossSheetForExport(Request::create('/reports/profit-loss-sheet', 'GET', $mayFilters));
        $this->assertSame(1500.0, $allBranches['sales']);
        $this->assertSame(150.0, $allBranches['expenseTotal']);
        $this->assertSame('All branches', $allBranches['branchLabel']);

        $branchOnly = $service->profitLossSheetForExport(Request::create('/reports/profit-loss-sheet', 'GET', [
            ...$mayFilters,
            'branch_id' => (string) $branchA->id,
        ]));
        $this->assertSame(1000.0, $branchOnly['sales']);
        $this->assertSame(100.0, $branchOnly['expenseTotal']);
        $this->assertStringContainsString($branchA->name, $branchOnly['branchLabel']);

        $print = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'profit-loss-sheet',
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'branch_id' => (string) $branchA->id,
        ]));
        $print->assertOk();
        $print->assertSee('Profit Loss Sheet', false);
        $print->assertSee($branchA->name, false);
        $print->assertSee('1,000.00', false);
    }

    public function test_income_expenditure_statement_scopes_by_branch_or_all_branches(): void
    {
        [$user, $branchA, $branchB, $expenseAccount, $incomeAccount] = $this->seedBranchesWithFinancialActivity(withIncome: true);

        $service = app(ReportDataService::class);
        $filters = [
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'branch_id' => '',
        ];

        $allBranches = $service->incomeExpenditureStatementForExport(Request::create('/reports/income-expenditure-statement', 'GET', $filters));
        $this->assertSame(150.0, $allBranches['expenditureMonthTotal']);
        $this->assertSame(75.0, $allBranches['incomeMonthTotal']);

        $branchOnly = $service->incomeExpenditureStatementForExport(Request::create('/reports/income-expenditure-statement', 'GET', [
            ...$filters,
            'branch_id' => (string) $branchB->id,
        ]));
        $this->assertSame(50.0, $branchOnly['expenditureMonthTotal']);
        $this->assertSame(0.0, $branchOnly['incomeMonthTotal']);

        $screen = $this->actingAs($user)->get(route('reports.income-expenditure-statement', $filters));
        $screen->assertOk();
        $screen->assertSee('All branches', false);

        $print = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'income-expenditure-statement',
            ...$filters,
        ]));
        $print->assertOk();
        $print->assertSee('Statement of Comprehensive Income', false);
    }

    /**
     * @return array{0: User, 1: Location, 2: Location, 3: LedgerAccount, 4?: LedgerAccount}
     */
    private function seedBranchesWithFinancialActivity(bool $withIncome = false): array
    {
        $branchA = Location::create([
            'code' => 'BR-A',
            'name' => 'Branch Alpha',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $branchB = Location::create([
            'code' => 'BR-B',
            'name' => 'Branch Beta',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branchA->id,
        ]);
        $customer = Customer::create([
            'customer_code' => 'C-FIN',
            'name' => 'Finance customer',
            'phone' => '01700000001',
            'is_active' => true,
        ]);
        $expenseAccount = LedgerAccount::create([
            'code' => 'EXP-001',
            'name' => 'Fuel',
            'type' => 'expense',
            'is_system' => false,
            'is_active' => true,
        ]);
        $walletAccount = LedgerAccount::create([
            'code' => 'AST-001',
            'name' => 'Cash wallet',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);
        $incomeAccount = null;
        if ($withIncome) {
            $incomeAccount = LedgerAccount::create([
                'code' => 'INC-001',
                'name' => 'Other income',
                'type' => 'income',
                'is_system' => false,
                'is_active' => true,
            ]);
        }

        foreach ([[$branchA, 1000.0], [$branchB, 500.0]] as [$branch, $total]) {
            SalesInvoice::create([
                'invoice_number' => 'INV-'.$branch->code,
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'invoice_date' => '2026-05-10',
                'status' => 'posted',
                'payment_status' => 'paid',
                'sale_type' => 'cash',
                'grand_total' => $total,
                'amount_paid' => $total,
                'amount_due' => 0,
                'created_by' => $user->id,
            ]);
        }

        $this->postExpense($user, $branchA, $expenseAccount, $walletAccount, 100.0);
        $this->postExpense($user, $branchB, $expenseAccount, $walletAccount, 50.0);

        if ($withIncome && $incomeAccount !== null) {
            $this->postIncome($user, $branchA, $incomeAccount, $walletAccount, 75.0);
        }

        return $withIncome
            ? [$user, $branchA, $branchB, $expenseAccount, $incomeAccount]
            : [$user, $branchA, $branchB, $expenseAccount];
    }

    private function postExpense(User $user, Location $branch, LedgerAccount $expense, LedgerAccount $wallet, float $amount): void
    {
        $entry = JournalEntry::create([
            'branch_id' => $branch->id,
            'entry_date' => '2026-05-12',
            'document_no' => 'EXP-'.$branch->code,
            'description' => 'Test expense',
            'source' => 'manual_expense',
            'status' => 'posted',
            'created_by' => $user->id,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'ledger_account_id' => $expense->id,
            'debit' => $amount,
            'credit' => 0,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'ledger_account_id' => $wallet->id,
            'debit' => 0,
            'credit' => $amount,
        ]);
    }

    private function postIncome(User $user, Location $branch, LedgerAccount $income, LedgerAccount $wallet, float $amount): void
    {
        $entry = JournalEntry::create([
            'branch_id' => $branch->id,
            'entry_date' => '2026-05-14',
            'document_no' => 'INC-'.$branch->code,
            'description' => 'Test income',
            'source' => 'manual_income',
            'status' => 'posted',
            'created_by' => $user->id,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'ledger_account_id' => $wallet->id,
            'debit' => $amount,
            'credit' => 0,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'ledger_account_id' => $income->id,
            'debit' => 0,
            'credit' => $amount,
        ]);
    }
}
