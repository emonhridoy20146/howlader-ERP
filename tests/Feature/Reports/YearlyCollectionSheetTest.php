<?php

namespace Tests\Feature\Reports;

use App\Exports\YearlyCollectionBranchSummarySheet;
use App\Exports\YearlyCollectionGridSheet;
use App\Models\Customer;
use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\InstallmentAdvanceService;
use App\Services\PermissionSyncService;
use App\Services\ReportDataService;
use App\Services\Reports\YearlyCollectionSheetService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class YearlyCollectionSheetTest extends TestCase
{
    use RefreshDatabase;

    private Location $branch;

    private User $admin;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 12:00:00');
        app(PermissionSyncService::class)->syncFromPolicies();
        $this->branch = Location::create(['code' => 'BR-Y', 'name' => 'Yearly Branch', 'type' => 'branch', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'branch_id' => $this->branch->id]);
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(string $name, ?Location $branch = null): Customer
    {
        return Customer::create([
            'customer_code' => 'Y-'.strtoupper(substr(md5($name), 0, 6)), 'name' => $name, 'phone' => '01710000'.str_pad((string) ++$this->seq, 3, '0', STR_PAD_LEFT),
            'is_active' => true, 'branch_id' => ($branch ?? $this->branch)->id,
        ]);
    }

    /**
     * @param  list<array{due: string, amount: float, pay?: list<array{0: string, 1: float}>}>  $schedules
     * @return array{0: SalesInvoice, 1: list<InstallmentSchedule>}
     */
    private function invoice(Customer $customer, string $number, array $schedules, string $planStatus = 'active', ?Location $branch = null, float $extraTotal = 0.0): array
    {
        $branch ??= $this->branch;
        $total = array_sum(array_column($schedules, 'amount'));
        $invoice = SalesInvoice::create([
            'invoice_number' => $number, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'invoice_date' => '2025-10-01',
            'sale_type' => 'installment', 'status' => 'posted', 'payment_status' => 'partial', 'subtotal' => $total, 'discount_total' => 0, 'tax_total' => 0,
            'grand_total' => $total + $extraTotal, 'amount_paid' => 0, 'amount_due' => $total, 'created_by' => $this->admin->id,
        ]);
        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'total_amount' => $total, 'down_payment' => 0, 'financed_amount' => $total,
            'remaining_amount' => $total, 'penalty_enabled' => false, 'penalty_rate' => 0, 'status' => $planStatus, 'created_by' => $this->admin->id,
        ]);

        $models = [];
        foreach ($schedules as $i => $row) {
            $paid = array_sum(array_column($row['pay'] ?? [], 1));
            $schedule = InstallmentSchedule::create([
                'installment_plan_id' => $plan->id, 'installment_no' => $i + 1, 'due_date' => $row['due'], 'due_amount' => $row['amount'],
                'paid_amount' => $paid, 'status' => $paid >= $row['amount'] ? 'paid' : 'pending',
            ]);
            foreach ($row['pay'] ?? [] as $k => [$date, $amount]) {
                $this->pay($invoice, $amount, $date, $schedule, 'inbound', $number.'-'.$i.'-'.$k);
            }
            $models[] = $schedule;
        }
        $invoice->update(['amount_paid' => array_sum(array_map(fn ($row) => array_sum(array_column($row['pay'] ?? [], 1)), $schedules))]);

        return [$invoice, $models];
    }

    private function pay(SalesInvoice $invoice, float $amount, string $date, ?InstallmentSchedule $schedule, string $direction, string $number): Payment
    {
        $payment = Payment::create([
            'payment_number' => 'P-'.$number, 'customer_id' => $invoice->customer_id, 'payment_date' => $date, 'payment_method' => 'cash',
            'amount' => $amount, 'direction' => $direction, 'recorded_by' => $this->admin->id,
        ]);
        PaymentAllocation::create(['payment_id' => $payment->id, 'sales_invoice_id' => $invoice->id, 'allocated_amount' => $amount]);
        if ($schedule) {
            InstallmentPaymentAllocation::create(['installment_schedule_id' => $schedule->id, 'payment_id' => $payment->id, 'allocated_amount' => $amount]);
        }

        return $payment;
    }

    private function request(array $params = []): Request
    {
        return new Request(array_merge(['year_from' => 2025, 'year_to' => 2026], $params));
    }

    private function sheet(array $params = []): array
    {
        $service = app(YearlyCollectionSheetService::class);

        return $service->forExport($service->filters($this->request($params)));
    }

    private function find(array $sheet, Customer $customer): ?array
    {
        return collect($sheet['customers'])->firstWhere('id', $customer->id);
    }

    private function seedScenario(): Customer
    {
        $customer = $this->customer('Scenario Sam');
        $this->invoice($customer, 'INV-1', [
            ['due' => '2025-11-10', 'amount' => 1000, 'pay' => [['2025-11-12', 1000]]], // paid in due month
            ['due' => '2025-12-10', 'amount' => 1000],                                  // missed
            ['due' => '2026-01-10', 'amount' => 1000, 'pay' => [['2026-01-20', 600]]],  // partial
            ['due' => '2026-02-10', 'amount' => 1000, 'pay' => [['2026-03-05', 1000]]], // paid a month late
            ['due' => '2026-08-10', 'amount' => 1000],                                  // upcoming
        ], 'active', null, 300.0); // invoice total 5300 = 5 installments + 300 down payment
        $this->invoice($customer, 'INV-2', [['due' => '2026-05-10', 'amount' => 500]]);

        return $customer;
    }

    public function test_grid_states_row_customer_and_grand_totals(): void
    {
        $customer = $this->seedScenario();
        $c = $this->find($this->sheet(), $customer);
        $inv1 = $c['invoices'][0]['months'];

        $this->assertSame(['paid', 1000.0], [$inv1['2025-11']['state'], $inv1['2025-11']['amount']]);
        $this->assertSame(['missed', 1000.0], [$inv1['2025-12']['state'], $inv1['2025-12']['amount']]);
        $this->assertSame(['partial', 600.0, 400.0], [$inv1['2026-01']['state'], $inv1['2026-01']['amount'], $inv1['2026-01']['shortfall']]);
        $this->assertSame('missed', $inv1['2026-02']['state']);   // paid in March, so February was missed
        $this->assertSame(['paid', 1000.0], [$inv1['2026-03']['state'], $inv1['2026-03']['amount']]);
        $this->assertSame(['upcoming', 1000.0], [$inv1['2026-08']['state'], $inv1['2026-08']['amount']]);
        $this->assertNull($inv1['2026-07']['state']);

        $row = $c['invoices'][0];
        $this->assertSame([5300.0, 2600.0, 1400.0, 2400.0, 2], [$row['payable'], $row['paid'], $row['due'], $row['outstanding'], $row['due_inst']]);
        $this->assertSame(5, $row['inst_total']);
        $this->assertSame('2026-08-10', $row['expires']);
        $this->assertSame('2026-02-10', $row['last_due']); // latest installment already due
        $this->assertSame('2/5', $row['inst_paid'].'/'.$row['inst_total']);
        $this->assertSame(1, $row['inst_partial']);
        $this->assertSame(0, $c['invoices'][1]['inst_partial']);

        $this->assertSame([5800.0, 2600.0, 1900.0, 2900.0], [$c['payable'], $c['paid'], $c['due'], $c['outstanding']]);
        // arrears carry forward: Dec missed 1000 + Jan shortfall 400
        $this->assertSame(['paid' => 600.0, 'due' => 400.0, 'arrears' => 1400.0, 'last_open' => 0], $c['months']['2026-01']);
        $this->assertSame(500.0, $c['months']['2026-05']['due']);

        $grand = $this->sheet()['grand'];
        $this->assertSame([5800.0, 2600.0, 1900.0, 2900.0], [$grand['payable'], $grand['paid'], $grand['due'], $grand['outstanding']]);
        $this->assertSame(1, $grand['customers']);
        $this->assertSame(2, $grand['invoices']);
    }

    public function test_total_payable_is_the_invoice_total_and_counted_once_per_invoice(): void
    {
        $customer = $this->seedScenario();
        $totals = SalesInvoice::query()->where('customer_id', $customer->id)->pluck('grand_total', 'invoice_number');
        $c = $this->find($this->sheet(), $customer);

        $this->assertEquals((float) $totals['INV-1'], $c['invoices'][0]['payable']);
        $this->assertEquals((float) $totals['INV-2'], $c['invoices'][1]['payable']);
        $this->assertEquals((float) $totals->sum(), $c['payable']);
        $this->assertEquals((float) $totals->sum(), $this->sheet()['grand']['payable']);
    }

    public function test_screen_and_excel_column_order(): void
    {
        $customer = $this->seedScenario();

        $html = $this->actingAs($this->admin)->get(route('reports.yearly-collection-sheet', ['year_from' => 2025, 'year_to' => 2026]))->assertOk()->getContent();
        preg_match('/<thead>(.*?)<\/thead>/s', $html, $m);
        $thead = strip_tags(preg_replace('/\s+/', ' ', $m[1]));
        $this->assertStringNotContainsString('Code', $thead);
        $this->assertMatchesRegularExpression('/SL Customer Invoice Invoice date Inst\. amount Inst\. paid\/total Last due Total payable Total paid Total due Outstanding Due inst\. No\. of installments Invoice expires 2025 2026/', $thead);

        $sheet = new YearlyCollectionGridSheet($this->sheet(), [], 'subtitle');
        $rows = $sheet->array();
        $head = $rows[5];
        $this->assertSame(['SL', 'Customer', 'Invoice', 'Invoice date', 'Inst. amount', 'Inst. paid/total', 'Last due', 'Total payable', 'Total paid', 'Total due', 'Outstanding', 'Due inst.', 'No. of installments', 'Invoice expires'], array_slice($head, 0, 14));
        $this->assertSame(1, $head[14]); // first month column right after "Invoice expires"
        $this->assertSame('2025', $rows[4][14]);

        $invoice = collect($rows)->first(fn ($r) => ($r[2] ?? null) === 'INV-1');
        $this->assertSame(5300.0, (float) $invoice[7]);
        $this->assertSame(5, $invoice[12]);
        $this->assertNotNull($invoice[13]); // Invoice expires as an Excel date
        $nov2025 = 14 + 10; // 2025-11 is the 11th month of the range
        $this->assertSame(1000.0, (float) $invoice[$nov2025]);
        $this->assertSame(1000.0, (float) $invoice[14 + 11]); // 2025-12 missed amount
        $this->assertCount(14 + 24, $invoice);
    }

    public function test_total_paid_is_the_invoice_paid_amount_not_the_in_range_payment_sum(): void
    {
        $customer = $this->customer('Lifetime Lou');
        [$invoice] = $this->invoice($customer, 'INV-LIFE', [
            ['due' => '2024-12-10', 'amount' => 500, 'pay' => [['2024-12-12', 400]]], // paid before the selected years
            ['due' => '2026-01-10', 'amount' => 500, 'pay' => [['2026-01-11', 200]]],
        ]);
        $this->assertEquals(600.0, (float) $invoice->fresh()->amount_paid);

        $c = $this->find($this->sheet(), $customer);
        $row = $c['invoices'][0];

        $this->assertSame(600.0, $row['paid']);   // invoice paid amount, 400 of it paid in 2024
        $this->assertSame(200.0, $row['months']['2026-01']['paid']); // month cell is still the month's payments
        $this->assertSame(600.0, $c['paid']);
        $this->assertSame(600.0, $this->sheet()['grand']['paid']);
    }

    public function test_fully_paid_invoices_are_excluded_unless_requested(): void
    {
        $customer = $this->customer('Paid Pat');
        $this->invoice($customer, 'INV-PAID', [['due' => '2026-01-10', 'amount' => 800, 'pay' => [['2026-01-10', 800]]]]);

        $this->assertNull($this->find($this->sheet(), $customer));
        $included = $this->find($this->sheet(['include_paid' => 1]), $customer);
        $this->assertNotNull($included);
        $this->assertSame('paid', $included['invoices'][0]['months']['2026-01']['state']);
    }

    public function test_cancelled_plans_deleted_invoices_and_refunds_are_excluded(): void
    {
        $cancelled = $this->customer('Cancelled Cleo');
        $this->invoice($cancelled, 'INV-CAN', [['due' => '2026-01-10', 'amount' => 500]], 'cancelled');

        $deleted = $this->customer('Deleted Dan');
        [$deletedInvoice] = $this->invoice($deleted, 'INV-DEL', [['due' => '2026-01-10', 'amount' => 500]]);
        $deletedInvoice->delete();

        $refund = $this->customer('Refund Rae');
        [$refundInvoice] = $this->invoice($refund, 'INV-REF', [['due' => '2026-01-10', 'amount' => 500, 'pay' => [['2026-01-10', 100]]]]);
        $this->pay($refundInvoice, 300, '2026-04-02', null, 'outbound', 'REFUND');

        $sheet = $this->sheet();
        $this->assertNull($this->find($sheet, $cancelled));
        $this->assertNull($this->find($sheet, $deleted));
        $row = $this->find($sheet, $refund)['invoices'][0];
        $this->assertNull($row['months']['2026-04']['state']);
        $this->assertSame(100.0, $row['paid']);
    }

    public function test_returned_invoices_are_excluded_even_when_they_still_show_a_due(): void
    {
        $returned = $this->customer('Returned Rita');
        [$returnedInvoice] = $this->invoice($returned, 'INV-RET', [['due' => '2026-01-10', 'amount' => 500]]);
        $returnedInvoice->update(['status' => 'returned']);

        $kept = $this->customer('Kept Kim');
        $this->invoice($kept, 'INV-KEPT', [['due' => '2026-01-10', 'amount' => 500]]);

        $sheet = $this->sheet();
        $this->assertNull($this->find($sheet, $returned));
        $this->assertNotNull($this->find($sheet, $kept));
    }

    public function test_year_range_validation(): void
    {
        $this->actingAs($this->admin)->get(route('reports.yearly-collection-sheet', ['year_from' => 2026, 'year_to' => 2025]))->assertSessionHasErrors('year_to');
        $this->actingAs($this->admin)->get(route('reports.yearly-collection-sheet', ['year_from' => 2023, 'year_to' => 2026]))->assertSessionHasErrors('year_to');
        $this->actingAs($this->admin)->get(route('reports.yearly-collection-sheet', ['year_from' => 1990, 'year_to' => 1991]))->assertSessionHasErrors('year_from');
        $this->actingAs($this->admin)->get(route('reports.yearly-collection-sheet', ['year_from' => 2025, 'year_to' => 2027]))->assertOk();

        $this->expectException(ValidationException::class);
        app(YearlyCollectionSheetService::class)->filters($this->request(['year_from' => 2020, 'year_to' => 2026]));
    }

    public function test_branch_scope_hides_other_branch_customers(): void
    {
        $other = Location::create(['code' => 'BR-Z', 'name' => 'Other Branch', 'type' => 'branch', 'is_active' => true]);
        $mine = $this->customer('Visible Vic');
        $theirs = $this->customer('Hidden Hal', $other);
        $this->invoice($mine, 'INV-MINE', [['due' => '2026-01-10', 'amount' => 500]]);
        $this->invoice($theirs, 'INV-THEIRS', [['due' => '2026-01-10', 'amount' => 500]], 'active', $other);
        $viewer = $this->viewer(['reports.view', 'reports.yearly_collection.view'], $this->branch);

        $this->actingAs($viewer)->get(route('reports.yearly-collection-sheet', ['year_from' => 2025, 'year_to' => 2026]))
            ->assertOk()->assertSee('INV-MINE')->assertDontSee('INV-THEIRS')->assertDontSee('Hidden Hal');
        $this->actingAs($viewer)->get(route('reports.yearly-collection-sheet', ['year_from' => 2025, 'year_to' => 2026, 'branch_id' => $other->id]))
            ->assertOk()->assertDontSee('INV-THEIRS');

        $adminSheet = $this->actingAs($this->admin)->get(route('reports.yearly-collection-sheet', ['year_from' => 2025, 'year_to' => 2026, 'branch_id' => $other->id]));
        $adminSheet->assertSee('INV-THEIRS')->assertDontSee('INV-MINE');
    }

    public function test_report_is_grouped_by_branch_with_branch_totals(): void
    {
        $other = Location::create(['code' => 'BR-O', 'name' => 'Another Branch', 'type' => 'branch', 'is_active' => true]);
        $sam = $this->customer('Both Sam');
        $hal = $this->customer('Other Hal', $other);
        $this->invoice($sam, 'INV-MAIN', [['due' => '2026-01-10', 'amount' => 1000, 'pay' => [['2026-01-11', 400]]]]);
        $this->invoice($sam, 'INV-OTHER', [['due' => '2026-02-10', 'amount' => 700]], 'active', $other);
        $this->invoice($hal, 'INV-HAL', [['due' => '2026-03-10', 'amount' => 300]], 'active', $other);

        $sheet = $this->sheet();
        $groups = collect($sheet['groups']);

        $this->assertSame(['Another Branch', 'Yearly Branch'], $groups->pluck('name')->all());
        $anotherBranch = $groups->firstWhere('name', 'Another Branch');
        $this->assertCount(2, $anotherBranch['customers']);
        $this->assertSame([1000.0, 0.0, 1000.0], [$anotherBranch['payable'], $anotherBranch['paid'], $anotherBranch['due']]);
        $mainBranch = $groups->firstWhere('name', 'Yearly Branch');
        $this->assertSame([1000.0, 400.0, 600.0], [$mainBranch['payable'], $mainBranch['paid'], $mainBranch['due']]);

        // Sam's invoices are split by their own branch, and Sam is counted once in the grand total.
        $this->assertSame(['Both Sam', 'Other Hal'], array_column($anotherBranch['customers'], 'name'));
        $this->assertSame(['INV-OTHER'], array_column($anotherBranch['customers'][0]['invoices'], 'number'));
        $this->assertSame(['INV-HAL'], array_column($anotherBranch['customers'][1]['invoices'], 'number'));
        $this->assertSame(['INV-MAIN'], array_column($mainBranch['customers'][0]['invoices'], 'number'));
        $this->assertSame(2, $sheet['grand']['customers']);
        $this->assertSame(3, $sheet['grand']['invoices']);
        $this->assertSame(2000.0, $sheet['grand']['payable']);
        $this->assertSame($anotherBranch['payable'] + $mainBranch['payable'], $sheet['grand']['payable']);

        $this->actingAs($this->admin)->get(route('reports.yearly-collection-sheet', ['year_from' => 2025, 'year_to' => 2026]))
            ->assertOk()->assertSeeInOrder(['Branch: Another Branch', 'INV-OTHER', 'Branch total — Another Branch', 'Branch: Yearly Branch', 'INV-MAIN', 'Branch total — Yearly Branch']);

        $this->actingAs($this->admin)->get(route('reports.print', ['slug' => 'yearly-collection-sheet', 'year_from' => 2025, 'year_to' => 2026]))
            ->assertOk()->assertSeeInOrder(['Branch: Another Branch', 'Branch summary — Another Branch', 'Branch: Yearly Branch', 'Branch summary — Yearly Branch', 'Summary of all branches', 'Grand total'])
            ->assertSee('page-break-before: always', false);

        $excel = (new YearlyCollectionGridSheet($sheet, [], 's'))->array();
        $labels = array_filter(array_column($excel, 0), 'is_string');
        $this->assertContains('Branch total — Another Branch — paid', $labels);
        $this->assertContains('Branch total — Yearly Branch — due', $labels);
        $summary = (new YearlyCollectionBranchSummarySheet($sheet))->array();
        $this->assertSame(['Another Branch', 'Yearly Branch', 'Grand total'], array_column($summary, 1));
        $this->assertSame(2000.0, (float) $summary[2][4]);
    }

    public function test_multi_branch_filter_and_scope(): void
    {
        $other = Location::create(['code' => 'BR-O2', 'name' => 'Second Branch', 'type' => 'branch', 'is_active' => true]);
        $third = Location::create(['code' => 'BR-O3', 'name' => 'Third Branch', 'type' => 'branch', 'is_active' => true]);
        $a = $this->customer('Alpha Ann');
        $b = $this->customer('Beta Bob', $other);
        $c = $this->customer('Gamma Gus', $third);
        $this->invoice($a, 'INV-A', [['due' => '2026-01-10', 'amount' => 100]]);
        $this->invoice($b, 'INV-B', [['due' => '2026-01-10', 'amount' => 100]], 'active', $other);
        $this->invoice($c, 'INV-C', [['due' => '2026-01-10', 'amount' => 100]], 'active', $third);

        $two = collect($this->sheet(['branch_ids' => [$this->branch->id, $other->id]])['groups'])->pluck('name')->all();
        $this->assertSame(['Second Branch', 'Yearly Branch'], $two);

        $legacy = collect($this->sheet(['branch_id' => (string) $third->id])['groups'])->pluck('name')->all();
        $this->assertSame(['Third Branch'], $legacy);

        $viewer = $this->viewer(['reports.view', 'reports.yearly_collection.view'], $this->branch);
        $this->actingAs($viewer)->get(route('reports.yearly-collection-sheet', ['year_from' => 2025, 'year_to' => 2026, 'branch_ids' => [$this->branch->id, $other->id]]))
            ->assertOk()->assertSee('INV-A')->assertDontSee('INV-B')->assertDontSee('INV-C');
        $this->actingAs($viewer)->get(route('reports.yearly-collection-sheet', ['year_from' => 2025, 'year_to' => 2026, 'branch_ids' => [$other->id]]))
            ->assertOk()->assertDontSee('INV-B');
    }

    public function test_permission_screen_print_pdf_and_excel(): void
    {
        $this->seedScenario();
        $q = ['year_from' => 2025, 'year_to' => 2026];

        $denied = $this->viewer(['reports.view', 'reports.export', 'reports.sales.view'], $this->branch);
        $this->actingAs($denied)->get(route('reports.yearly-collection-sheet', $q))->assertForbidden();

        $allowed = $this->viewer(['reports.view', 'reports.export', 'reports.yearly_collection.view'], $this->branch);
        $this->actingAs($allowed)->get(route('reports.yearly-collection-sheet', $q))->assertOk()->assertSee('INV-1')->assertSee('Customer total');
        $this->actingAs($allowed)->get(route('reports.print', ['slug' => 'yearly-collection-sheet'] + $q))->assertOk()->assertSee('Grand total')->assertSee('Accountant');

        $this->actingAs($this->admin)->get(route('reports.export', ['slug' => 'yearly-collection-sheet', 'format' => 'pdf'] + $q))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->admin)->get(route('reports.export', ['slug' => 'yearly-collection-sheet', 'format' => 'xlsx'] + $q))->assertOk()->assertDownload();
        $this->actingAs($this->admin)->get(route('reports.index'))->assertOk()->assertSee('Yearly Collection Sheet');
        $this->actingAs($denied)->get(route('reports.index'))->assertOk()->assertDontSee('Yearly Collection Sheet');
    }

    public function test_credit_customers_are_listed_branch_wise_with_running_unpaid(): void
    {
        $this->seedScenario();
        $customer = $this->customer('Credit Carl');
        $invoice = SalesInvoice::create([
            'invoice_number' => 'CR-1', 'branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'invoice_date' => '2026-01-05', 'credit_due_date' => '2026-03-31',
            'sale_type' => 'credit', 'status' => 'posted', 'payment_status' => 'partial', 'subtotal' => 2000, 'discount_total' => 0, 'tax_total' => 0,
            'grand_total' => 2000, 'amount_paid' => 0, 'amount_due' => 2000, 'created_by' => $this->admin->id,
        ]);
        $this->pay($invoice, 500, '2026-02-10', null, 'inbound', 'CR1-a');
        $this->pay($invoice, 300, '2026-05-02', null, 'inbound', 'CR1-b');
        $invoice->update(['amount_paid' => 800]);

        $credit = $this->sheet()['credit'];
        $group = $credit['groups'][$this->branch->id];
        $row = $group['customers'][0]['invoices'][0];
        $this->assertSame('CR-1', $row['number']);
        $this->assertSame([2000.0, 800.0, 1200.0], [$row['payable'], $row['paid'], $row['due']]);
        $this->assertSame(0.0, $row['months']['2025-12']['arrears']);   // before the sale
        $this->assertSame(2000.0, $row['months']['2026-01']['arrears']);
        $this->assertSame([500.0, 1500.0], [$row['months']['2026-02']['paid'], $row['months']['2026-02']['arrears']]);
        $this->assertSame(1, $row['months']['2026-03']['due_open']);       // credit due month, not cleared
        $this->assertSame(1200.0, $row['months']['2026-05']['arrears']);
        $this->assertSame(0.0, $row['months']['2026-07']['arrears']);     // future
        $this->assertTrue($row['overdue']);
        $this->assertSame(1, $credit['grand']['customers']);
        $this->assertNull($this->find($this->sheet(), $customer)); // not mixed into installment customers

        $q = ['year_from' => 2025, 'year_to' => 2026];
        $this->actingAs($this->admin)->get(route('reports.yearly-collection-sheet', $q))->assertOk()
            ->assertSeeInOrder(['Installment customers', 'INV-1', 'Credit customers', 'CR-1']);
        $this->actingAs($this->admin)->get(route('reports.print', ['slug' => 'yearly-collection-sheet'] + $q))->assertOk()
            ->assertSeeInOrder(['Installment customers', 'INV-1', 'Credit customers', 'CR-1', 'Credit grand total']);

        $invoice->update(['amount_paid' => 2000]);
        $this->assertSame([], $this->sheet()['credit']['groups']);
    }

    public function test_years_without_activity_are_hidden(): void
    {
        $customer = $this->customer('Only 2026');
        $this->invoice($customer, 'INV-26', [['due' => '2026-02-10', 'amount' => 400]]);
        $sheet = $this->sheet(['year_from' => 2024, 'year_to' => 2026]);

        $vis = YearlyCollectionSheetService::visibleYears($sheet['months'], YearlyCollectionSheetService::invoiceMonthMaps($sheet['customers']));
        $this->assertSame([2026 => 12], $vis['years']);
        $this->assertCount(12, $vis['months']);

        // nothing active anywhere: keep every year
        $this->assertSame([2024 => 12, 2025 => 12, 2026 => 12], YearlyCollectionSheetService::visibleYears($sheet['months'], [])['years']);

        $this->actingAs($this->admin)->get(route('reports.print', ['slug' => 'yearly-collection-sheet', 'year_from' => 2024, 'year_to' => 2026]))
            ->assertOk()->assertDontSee('class="yr">2024<', false)->assertDontSee('class="yr">2025<', false)->assertSee('class="yr">2026<', false);
    }

    public function test_print_page_is_legal_landscape_and_a3_for_three_years(): void
    {
        $this->seedScenario();

        $two = $this->actingAs($this->admin)->get(route('reports.print', ['slug' => 'yearly-collection-sheet', 'year_from' => 2025, 'year_to' => 2026]))->assertOk();
        $two->assertSee('size: LEGAL landscape', false)->assertDontSee('size: A3 landscape', false);

        $three = $this->actingAs($this->admin)->get(route('reports.print', ['slug' => 'yearly-collection-sheet', 'year_from' => 2024, 'year_to' => 2026]))->assertOk();
        $three->assertSee('size: A3 landscape', false);
    }

    public function test_no_lazy_loading_and_bounded_query_count(): void
    {
        Model::preventLazyLoading(true);
        $service = app(YearlyCollectionSheetService::class);

        foreach (range(1, 3) as $i) {
            $this->invoice($this->customer("Few $i"), "INV-F$i", [['due' => '2026-01-10', 'amount' => 100], ['due' => '2026-02-10', 'amount' => 100, 'pay' => [['2026-02-11', 50]]]]);
        }
        $count = function () use ($service): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $service->paginated($service->filters($this->request()));
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $few = $count();

        foreach (range(1, 12) as $i) {
            $this->invoice($this->customer("Many $i"), "INV-M$i", [['due' => '2026-03-10', 'amount' => 100], ['due' => '2026-04-10', 'amount' => 100]]);
        }
        $many = $count();

        $this->assertSame($few, $many, 'query count must not grow with customers');
        $this->assertLessThanOrEqual(12, $many);
    }

    public function test_paid_numbers_match_the_monthly_collection_report(): void
    {
        $customer = $this->customer('Match Mia');
        $this->invoice($customer, 'INV-MATCH', [['due' => '2026-06-20', 'amount' => 1000, 'pay' => [['2026-06-05', 300]]]]);
        $this->invoice($customer, 'INV-MATCH-2', [['due' => '2026-06-25', 'amount' => 700, 'pay' => [['2026-06-08', 200]]]]);

        $monthly = app(ReportDataService::class)->monthlyCollectionTotals(new Request([
            'branch_id' => (string) $this->branch->id, 'date_from' => '2026-06-01', 'date_to' => '2026-06-30',
        ]));
        $grand = $this->sheet(['branch_id' => (string) $this->branch->id])['grand'];

        $this->assertSame(500.0, $monthly['period_paid']);
        $this->assertSame($monthly['period_paid'], $grand['months']['2026-06']['paid']);
    }

    public function test_down_payment_can_be_excluded(): void
    {
        $customer = $this->customer('Down Dee');
        [$invoice] = $this->invoice($customer, 'INV-DOWN', [['due' => '2026-03-10', 'amount' => 500]]);
        $advance = $this->pay($invoice, 250, '2026-02-01', null, 'inbound', 'ADV');
        $advance->update(['reference_no' => InstallmentAdvanceService::REFERENCE]);

        $with = $this->find($this->sheet(), $customer)['invoices'][0];
        $without = $this->find($this->sheet(['include_down_payment' => 0]), $customer)['invoices'][0];

        $this->assertSame(250.0, $with['months']['2026-02']['amount']);
        $this->assertNull($without['months']['2026-02']['state']);
    }

    /** @param list<string> $slugs */
    private function viewer(array $slugs, Location $branch): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $user->accessibleLocations()->sync([$branch->id]);
        $role = Role::query()->create(['name' => 'R '.uniqid(), 'slug' => 'r-'.uniqid()]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id')->all());
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
