<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\SalesInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * For an installment invoice that is still open, credits the installment schedule with the amount
 * it is missing so that the schedule balance equals the invoice header's amount due.
 *
 * Use only after confirming against the paper slip that the header's amount due is correct.
 * It only ever ADDS paid credit to schedules (oldest open installment first); it never lowers
 * paid amounts and never touches the invoice header. Preview by default; nothing is written
 * without --apply.
 */
class AlignInstallmentPlanToInvoiceDueCommand extends Command
{
    protected $signature = 'invoices:align-plan-to-due
                            {invoice : sales_invoices.id or invoice_number}
                            {--apply : Write the changes (default is a preview)}';

    protected $description = 'Credit an open installment plan so its remaining balance equals the invoice amount due (preview by default)';

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

        if ($invoice->sale_type !== 'installment' || ! in_array($invoice->status, ['posted', 'active'], true)) {
            $this->error('Only posted/active installment invoices are supported.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $failure = null;

        $lines = DB::transaction(function () use ($invoice, $apply, &$failure): array {
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $plan = InstallmentPlan::query()
                ->where('sales_invoice_id', $invoice->id)
                ->lockForUpdate()
                ->with(['schedules' => fn ($q) => $q->orderBy('installment_no')])
                ->first();

            if (! $plan instanceof InstallmentPlan) {
                $failure = 'Invoice has no installment plan.';

                return [];
            }

            $due = round((float) $invoice->amount_due, 2);
            $paid = round((float) $invoice->amount_paid, 2);
            $grand = round((float) $invoice->grand_total, 2);

            if (abs($paid + $due - $grand) > self::TOLERANCE) {
                $failure = sprintf('Header does not add up (paid %.2f + due %.2f != grand total %.2f). Fix the header first.', $paid, $due, $grand);

                return [];
            }

            $remaining = round((float) $plan->schedules->sum(fn (InstallmentSchedule $s): float => $s->remainingDue()), 2);
            $gap = round($remaining - $due, 2);

            if (abs($gap) <= self::TOLERANCE) {
                return ['Plan already matches the invoice due ('.number_format($due, 2).'). Nothing to do.'];
            }

            if ($gap < 0) {
                $failure = sprintf('Schedule remaining %.2f is LOWER than invoice due %.2f; this command only adds credit. Review manually.', $remaining, $due);

                return [];
            }

            $lines = [sprintf('invoice due %.2f, schedule remaining %.2f: crediting the %.2f difference', $due, $remaining, $gap)];
            $before = [];
            $left = $gap;

            foreach ($plan->schedules as $schedule) {
                if ($left <= 0.009) {
                    break;
                }

                $open = $schedule->remainingDue();
                if ($open <= 0.009) {
                    continue;
                }

                $credit = round(min($open, $left), 2);
                $newPaid = round((float) $schedule->paid_amount + $credit, 2);
                $lines[] = sprintf('installment #%d: paid %.2f -> %.2f', $schedule->installment_no, $schedule->paid_amount, $newPaid);
                $before['schedules'][$schedule->id] = ['paid_amount' => $schedule->paid_amount, 'status' => $schedule->status];
                $left = round($left - $credit, 2);

                if ($apply) {
                    $schedule->paid_amount = $newPaid;
                    $schedule->status = $schedule->computeStatus();
                    $schedule->save();
                }
            }

            $lines[] = sprintf('plan: remaining %.2f -> %.2f', $plan->remaining_amount, $due);
            $before['plan'] = ['remaining_amount' => $plan->remaining_amount, 'status' => $plan->status];

            if ($apply) {
                $plan->update(['remaining_amount' => $due, 'status' => $due < 0.02 ? 'completed' : 'active']);

                AuditLog::create([
                    'actor_user_id' => null,
                    'action' => 'sales_invoice.plan_aligned_to_due',
                    'entity_type' => SalesInvoice::class,
                    'entity_id' => $invoice->id,
                    'before_snapshot' => $before,
                    'after_snapshot' => ['credited' => $gap, 'invoice_due' => $due, 'changes' => $lines],
                    'ip_address' => null,
                ]);
            }

            return $lines;
        });

        if ($failure !== null) {
            $this->error($failure);

            return self::FAILURE;
        }

        $this->line("Invoice {$invoice->invoice_number} (id {$invoice->id})");
        foreach ($lines as $line) {
            $this->line(($apply ? ' applied: ' : ' would do: ').$line);
        }

        $this->comment($apply
            ? 'Applied. Re-run invoices:check-integrity --invoice='.$invoice->id.' to confirm.'
            : 'Preview only. Re-run with --apply to write these changes.');

        return self::SUCCESS;
    }
}
