<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\InstallmentAdvanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExportMisallocatedAdvanceInstallmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $path = storage_path('app/testing/misallocated-advance-export.csv');
        if (File::exists($path)) {
            File::delete($path);
        }

        parent::tearDown();
    }

    public function test_export_command_writes_csv_for_native_misallocated_invoices(): void
    {
        [$invoice] = $this->seedMisallocatedNativeInvoice();
        $output = storage_path('app/testing/misallocated-advance-export.csv');

        $this->artisan('installments:export-misallocated-advance', [
            '--output' => $output,
            '--all' => true,
        ])->assertSuccessful();

        $this->assertFileExists($output);

        $lines = file($output, FILE_IGNORE_NEW_LINES);
        $this->assertNotFalse($lines);
        $this->assertSame(
            implode(',', InstallmentAdvanceService::misallocatedAdvanceExportHeaders()),
            $lines[0],
        );
        $this->assertStringContainsString((string) $invoice->id, $lines[1]);
        $this->assertStringContainsString('INV-EXP-MIS', $lines[1]);
        $this->assertStringContainsString('5000', $lines[1]);
    }

    public function test_export_command_excludes_dizicashier_imports(): void
    {
        [$invoice] = $this->seedMisallocatedNativeInvoice();
        $invoice->update(['dizicashier_uuid' => 'dizi-sale-export-1']);
        $output = storage_path('app/testing/misallocated-advance-export.csv');

        $this->artisan('installments:export-misallocated-advance', [
            '--output' => $output,
            '--all' => true,
        ])->assertSuccessful();

        $this->assertFileDoesNotExist($output);
    }

    /**
     * @return array{0: SalesInvoice}
     */
    private function seedMisallocatedNativeInvoice(): array
    {
        $branch = Location::create([
            'code' => 'BR-EXP',
            'name' => 'Export Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-EXP',
            'name' => 'Export Customer',
            'phone' => '01700006666',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-EXP-MIS',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 20000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 20000,
            'amount_paid' => 5000,
            'amount_due' => 15000,
            'installment_down_payment' => 5000,
            'installment_count' => 3,
            'installment_first_due_date' => now()->addMonth()->toDateString(),
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 20000,
            'down_payment' => 5000,
            'financed_amount' => 15000,
            'remaining_amount' => 10000,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $firstSchedule = InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'due_amount' => 5000,
            'paid_amount' => 5000,
            'status' => 'paid',
        ]);

        foreach ([2, 3] as $no) {
            InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => $no,
                'due_date' => now()->addMonths($no)->toDateString(),
                'due_amount' => 5000,
            ]);
        }

        $payment = Payment::create([
            'payment_number' => 'PAY-EXP-MIS',
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'amount' => 5000,
            'direction' => 'inbound',
            'reference_no' => InstallmentAdvanceService::REFERENCE,
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 5000,
            'settlement_discount' => 0,
        ]);

        InstallmentPaymentAllocation::create([
            'installment_schedule_id' => $firstSchedule->id,
            'payment_id' => $payment->id,
            'allocated_amount' => 5000,
        ]);

        return [$invoice];
    }
}
