<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\SalesInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repairs one invoice whose records disagree (see invoices:check-integrity).
 *
 * - Removes payment allocation rows whose payment record no longer exists.
 * - When the invoice header is settled (amount due 0, paid = grand total), closes any
 *   installment schedule balance and completes the plan so it matches the header.
 *
 * The header (amount_paid / amount_due) is never changed: it is treated as the source of truth
 * because bad-debt write-offs and settlement discounts are recorded there.
 * Preview by default; nothing is written without --apply.
 */
class RepairInvoiceCommand extends Command
{
    protected $signature = 'invoices:repair-invoice
                            {invoice : sales_invoices.id or invoice_number}
                            {--apply : Write the changes (default is a preview)}';

    protected $description = 'Preview or apply the repair of one invoice: remove orphan allocations and sync a settled installment plan to the header';

    private const TOLERANCE = 0.02;

    public function handle(): int
    {
        $needle = (string) $this->argument('invoice');

        $invoice = SalesInvoice::query()
            ->where('invoice_number', $needle)
            ->when(is_numeric($needle), fn ($q) => $q->orWhere('id', (int) $needle))
            ->first();

        if (! $invoice instanceof SalesInvoice) {
            $this->error('No invoice found for: '.$needle);

            return self::FAILURE;
        }

        if (! in_array($invoice->status, ['posted', 'active'], true)) {
            $this->error("Invoice {$invoice->invoice_number} has status '{$invoice->status}'; only posted/active invoices are repaired.");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        $changes = DB::transaction(function () use ($invoice, $apply): array {
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $changes = [];
            $before = [];

            $orphans = DB::table('payment_allocations as a')
                ->leftJoin('payments as p', 'p.id', '=', 'a.payment_id')
                ->whereNull('p.id')
                ->where('a.sales_invoice_id', $invoice->id)
                ->get(['a.id', 'a.payment_id', 'a.allocated_amount']);

            foreach ($orphans as $row) {
                $changes[] = sprintf('delete orphan allocation #%d (%.2f, payment #%d no longer exists)', $row->id, $row->allocated_amount, $row->payment_id);
                $before['orphan_allocations'][] = (array) $row;
            }

            $paid = round((float) $invoice->amount_paid, 2);
            $due = round((float) $invoice->amount_due, 2);
            $grand = round((float) $invoice->grand_total, 2);
            $settled = $due <= 0.009 && $paid + self::TOLERANCE >= $grand;

            $plan = $invoice->sale_type === 'installment'
                ? InstallmentPlan::query()->where('sales_invoice_id', $invoice->id)->lockForUpdate()->with('schedules')->first()
                : null;

            if ($plan instanceof InstallmentPlan) {
                if (! $settled) {
                    $changes[] = sprintf('installment plan left untouched: header is not settled (paid %.2f, due %.2f)', $paid, $due);
                } else {
                    foreach ($plan->schedules as $schedule) {
                        $remaining = $schedule->remainingDue();
                        if ($remaining > 0.009) {
                            $target = round((float) $schedule->due_amount + (float) $schedule->late_fee_amount, 2);
                            $changes[] = sprintf('installment #%d: paid %.2f -> %.2f (closes %.2f remaining)', $schedule->installment_no, $schedule->paid_amount, $target, $remaining);
                            $before['schedules'][$schedule->id] = ['paid_amount' => $schedule->paid_amount, 'status' => $schedule->status];
                            if ($apply) {
                                $schedule->paid_amount = $target;
                                $schedule->status = $schedule->computeStatus();
                                $schedule->save();
                            }
                        }
                    }

                    if ((float) $plan->remaining_amount > 0.009 || $plan->status !== 'completed') {
                        $changes[] = sprintf('plan: remaining %.2f -> 0.00, status %s -> completed', $plan->remaining_amount, $plan->status);
                        $before['plan'] = ['remaining_amount' => $plan->remaining_amount, 'status' => $plan->status];
                        if ($apply) {
                            $plan->update(['remaining_amount' => 0, 'status' => 'completed']);
                        }
                    }
                }
            }

            if ($settled && $invoice->payment_status !== 'paid') {
                $changes[] = sprintf('invoice payment_status: %s -> paid', $invoice->payment_status);
                $before['payment_status'] = $invoice->payment_status;
                if ($apply) {
                    $invoice->update(['payment_status' => 'paid']);
                }
            }

            if ($apply && $orphans->isNotEmpty()) {
                DB::table('payment_allocations')->whereIn('id', $orphans->pluck('id'))->delete();
            }

            if ($apply && $before !== []) {
                AuditLog::create([
                    'actor_user_id' => null,
                    'action' => 'sales_invoice.integrity_repair',
                    'entity_type' => SalesInvoice::class,
                    'entity_id' => $invoice->id,
                    'before_snapshot' => $before,
                    'after_snapshot' => ['changes' => $changes],
                    'ip_address' => null,
                ]);
            }

            return $changes;
        });

        $this->line("Invoice {$invoice->invoice_number} (id {$invoice->id})");

        if ($changes === []) {
            $this->info('Nothing to repair.');

            return self::SUCCESS;
        }

        foreach ($changes as $change) {
            $this->line(($apply ? ' applied: ' : ' would do: ').$change);
        }

        if (! $apply) {
            $this->comment('Preview only. Re-run with --apply to write these changes.');
        } else {
            $this->info('Repair applied. Re-run invoices:check-integrity --invoice='.$invoice->id.' to confirm.');
        }

        return self::SUCCESS;
    }
}
