<?php

namespace App\Console\Commands;

use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\SalesInvoice;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixZeroDuePaymentStatusCommand extends Command
{
    protected $signature = 'invoices:fix-zero-due-payment-status
                            {--dry-run : Report fixes without saving}
                            {--invoice= : Limit to sales_invoices.id or invoice_number}
                            {--recalculate-customers : Re-sync customer outstanding_balance for affected customers}';

    protected $description = 'Sync payment_status and installment plans for posted/active invoices with zero amount due';

    public function handle(PaymentService $paymentService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $invoiceOpt = $this->option('invoice');
        $recalculateCustomers = (bool) $this->option('recalculate-customers');

        $query = SalesInvoice::query()
            ->whereIn('status', ['posted', 'active'])
            ->where('amount_due', '<=', 0.009)
            ->where(function ($q): void {
                $q->where('payment_status', '!=', 'paid')
                    ->orWhere(function ($numberQ): void {
                        $numberQ->whereNull('invoice_number')
                            ->orWhere('invoice_number', '');
                    })
                    ->orWhereHas('installmentPlan', function ($planQ): void {
                        $planQ->where('status', '!=', 'completed')
                            ->orWhere('remaining_amount', '>', 0.009);
                    })
                    ->orWhereHas('installmentPlan.schedules', function ($schedQ): void {
                        $schedQ->whereRaw('(due_amount + late_fee_amount - paid_amount) > 0.009');
                    });
            })
            ->with(['installmentPlan.schedules']);

        if ($invoiceOpt !== null && (string) $invoiceOpt !== '') {
            $needle = (string) $invoiceOpt;
            if (is_numeric($needle)) {
                $query->where(function ($q) use ($needle): void {
                    $q->whereKey((int) $needle)
                        ->orWhere('invoice_number', $needle);
                });
            } else {
                $query->where('invoice_number', $needle);
            }

            if (! (clone $query)->exists()) {
                $this->error('No matching invoice found for: '.$needle);

                return self::FAILURE;
            }
        }

        $invoices = $query->orderBy('id')->get();

        if ($invoices->isEmpty()) {
            $this->info('No settled invoices need payment or installment corrections.');

            return self::SUCCESS;
        }

        $changes = [];
        $planUpdates = 0;
        $numberAssignments = 0;
        $customerIds = [];

        foreach ($invoices as $invoice) {
            $beforeStatus = (string) $invoice->payment_status;
            $planBefore = null;
            $planAfter = null;

            if ($invoice->installmentPlan instanceof InstallmentPlan) {
                $planBefore = (string) $invoice->installmentPlan->status;
            }

            $needsNumber = $invoice->invoice_number === null || trim((string) $invoice->invoice_number) === '';

            if (! $dryRun) {
                DB::transaction(function () use ($invoice, $paymentService, &$planUpdates, &$planBefore, &$planAfter, &$numberAssignments, $needsNumber): void {
                    $locked = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

                    if ((float) $locked->amount_due > 0.009) {
                        return;
                    }

                    if ($locked->payment_status !== 'paid') {
                        $locked->update(['payment_status' => 'paid']);
                    }

                    $locked->refresh();
                    if ($needsNumber) {
                        $beforeNumber = $locked->invoice_number;
                        $paymentService->assignInvoiceNumberWhenCollected($locked);
                        $locked->refresh();
                        if ($locked->invoice_number !== null && trim((string) $locked->invoice_number) !== ''
                            && ($beforeNumber === null || trim((string) $beforeNumber) === '')) {
                            $numberAssignments++;
                        }
                    }

                    $plan = InstallmentPlan::query()
                        ->where('sales_invoice_id', $locked->id)
                        ->lockForUpdate()
                        ->with(['schedules' => fn ($q) => $q->orderBy('installment_no'), 'salesInvoice'])
                        ->first();

                    if ($plan instanceof InstallmentPlan) {
                        $planBefore = (string) $plan->status;
                        $this->refreshInstallmentPlan($plan);
                        $planAfter = (string) $plan->fresh()->status;
                        $planUpdates++;
                    }
                });
            } else {
                if ($invoice->installmentPlan instanceof InstallmentPlan) {
                    $owing = (float) $invoice->installmentPlan->schedules
                        ->sum(fn (InstallmentSchedule $s) => $s->remainingDue());
                    if ((float) $invoice->amount_due <= 0.009) {
                        $owing = 0.0;
                    }
                    $planAfter = $owing < 0.02 ? 'completed' : 'active';
                }
            }

            $customerIds[] = (int) $invoice->customer_id;

            $changes[] = [
                'id' => (int) $invoice->id,
                'number' => (string) ($invoice->fresh()->invoice_number ?? $invoice->invoice_number ?? ''),
                'sale_type' => (string) $invoice->sale_type,
                'before' => $beforeStatus,
                'after' => 'paid',
                'plan_before' => $planBefore ?? '—',
                'plan_after' => $planAfter ?? '—',
                'assigned_number' => $needsNumber && ! $dryRun,
            ];
        }

        $this->info('Settled invoices needing correction: '.$invoices->count());

        if ($this->output->isVerbose() || $dryRun) {
            $this->table(
                ['ID', 'Invoice #', 'Type', 'Payment before', 'Payment after', 'Plan before', 'Plan after'],
                collect($changes)->map(fn (array $row): array => [
                    $row['id'],
                    $row['number'],
                    $row['sale_type'],
                    $row['before'],
                    $row['after'],
                    $row['plan_before'],
                    $row['plan_after'],
                ])->all(),
            );
        }

        if ($dryRun) {
            $this->comment('Dry run — no rows updated. Run without --dry-run to apply.');

            return self::SUCCESS;
        }

        if ($planUpdates > 0) {
            $this->info('Corrected '.$planUpdates.' installment plan(s).');
        }

        $paymentFixes = collect($changes)->filter(fn (array $row): bool => $row['before'] !== 'paid')->count();
        if ($paymentFixes > 0) {
            $this->info('Updated payment_status on '.$paymentFixes.' invoice(s).');
        }

        if ($numberAssignments > 0) {
            $this->info('Assigned invoice number on '.$numberAssignments.' invoice(s).');
        }

        if ($paymentFixes === 0 && $planUpdates === 0 && $numberAssignments === 0) {
            $this->info('No updates were required.');
        }

        if ($recalculateCustomers && $customerIds !== []) {
            foreach (array_unique($customerIds) as $customerId) {
                $this->call('customers:recalculate-outstanding', [
                    '--customer' => $customerId,
                ]);
            }
            $this->comment('Re-synced outstanding balance for '.count(array_unique($customerIds)).' customer(s).');
        }

        return self::SUCCESS;
    }

    private function refreshInstallmentPlan(InstallmentPlan $plan): void
    {
        $invoiceDue = (float) $plan->salesInvoice?->amount_due;

        foreach ($plan->schedules as $schedule) {
            $locked = InstallmentSchedule::query()->lockForUpdate()->find($schedule->id);
            if (! $locked) {
                continue;
            }

            if ($invoiceDue <= 0.009 && $locked->remainingDue() > 0.009) {
                $saleType = (string) ($plan->salesInvoice?->sale_type ?? '');
                $grandTotal = round((float) ($plan->salesInvoice?->grand_total ?? 0), 2);
                $collected = round((float) ($plan->salesInvoice?->amount_paid ?? 0), 2);

                if ($saleType === 'installment' && $collected + 0.02 < $grandTotal) {
                    continue;
                }

                $locked->paid_amount = round((float) $locked->due_amount + (float) $locked->late_fee_amount, 2);
            }

            $computed = $locked->computeStatus();
            if ($locked->status !== $computed) {
                $locked->update([
                    'paid_amount' => $locked->paid_amount,
                    'status' => $computed,
                ]);
            } elseif ($invoiceDue <= 0.009 && $locked->remainingDue() <= 0.009 && $locked->status !== 'paid') {
                $locked->update(['status' => 'paid']);
            }
        }

        $plan->load('schedules');
        $owing = (float) $plan->schedules->sum(fn (InstallmentSchedule $s) => $s->remainingDue());

        if ($invoiceDue <= 0.009) {
            $saleType = (string) ($plan->salesInvoice?->sale_type ?? '');
            $grandTotal = round((float) ($plan->salesInvoice?->grand_total ?? 0), 2);
            $collected = round((float) ($plan->salesInvoice?->amount_paid ?? 0), 2);

            if ($saleType !== 'installment' || $collected + 0.02 >= $grandTotal) {
                $owing = 0.0;
            }
        }

        $plan->update([
            'remaining_amount' => max(0, round($owing, 2)),
            'status' => $owing < 0.02 ? 'completed' : 'active',
        ]);
    }
}
