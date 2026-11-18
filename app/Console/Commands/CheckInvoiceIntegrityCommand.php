<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only report: flags invoices whose header, installment plan and payment
 * allocations disagree. Never modifies data.
 */
class CheckInvoiceIntegrityCommand extends Command
{
    protected $signature = 'invoices:check-integrity
                            {--invoice= : Limit to sales_invoices.id or invoice_number}
                            {--fail : Exit with failure status when problems are found}';

    protected $description = 'Report invoices where paid/due, installment plan and payment allocations do not agree (read-only)';

    private const TOLERANCE = 0.02;

    public function handle(): int
    {
        $problems = [];

        $needle = $this->option('invoice');
        $needle = ($needle !== null && (string) $needle !== '') ? (string) $needle : null;
        $needleIds = $needle === null ? null : DB::table('sales_invoices')
            ->where('invoice_number', $needle)
            ->when(is_numeric($needle), fn ($q) => $q->orWhere('id', (int) $needle))
            ->pluck('id')
            ->all();

        $orphans = DB::table('payment_allocations as a')
            ->leftJoin('payments as p', 'p.id', '=', 'a.payment_id')
            ->whereNull('p.id')
            ->when($needleIds !== null, fn ($q) => $q->whereIn('a.sales_invoice_id', $needleIds))
            ->selectRaw('a.sales_invoice_id as invoice_id, COUNT(*) as n, SUM(a.allocated_amount) as amount')
            ->groupBy('a.sales_invoice_id')
            ->get();

        foreach ($orphans as $row) {
            $problems[(int) $row->invoice_id][] = sprintf(
                'orphan allocations: %d row(s), %.2f (payment record missing)',
                $row->n,
                $row->amount
            );
        }

        $query = DB::table('sales_invoices as i')
            ->leftJoin('installment_plans as ip', 'ip.sales_invoice_id', '=', 'i.id')
            ->whereNull('i.deleted_at')
            ->whereIn('i.status', ['posted', 'active'])
            ->select([
                'i.id', 'i.invoice_number', 'i.sale_type', 'i.grand_total', 'i.amount_paid', 'i.amount_due',
                'ip.remaining_amount as plan_remaining',
                DB::raw('(SELECT COALESCE(SUM(sch.due_amount + sch.late_fee_amount - sch.paid_amount), 0) FROM installment_schedules sch WHERE sch.installment_plan_id = ip.id AND (sch.due_amount + sch.late_fee_amount - sch.paid_amount) > 0) as schedule_remaining'),
                DB::raw('(SELECT COALESCE(SUM(a.allocated_amount + COALESCE(a.settlement_discount, 0)), 0) FROM payment_allocations a JOIN payments p ON p.id = a.payment_id WHERE a.sales_invoice_id = i.id) as allocated'),
                DB::raw('(SELECT COALESCE(SUM(w.amount), 0) FROM invoice_debt_writeoffs w WHERE w.sales_invoice_id = i.id) as written_off'),
            ]);

        if ($needleIds !== null) {
            $query->whereIn('i.id', $needleIds);
        }

        $names = [];

        $query->orderBy('i.id')->chunk(500, function ($rows) use (&$problems, &$names): void {
            foreach ($rows as $r) {
                $names[(int) $r->id] = $r->invoice_number;
                $grand = (float) $r->grand_total;
                $paid = (float) $r->amount_paid;
                $due = (float) $r->amount_due;

                if (abs($paid + $due - $grand) > self::TOLERANCE) {
                    $problems[(int) $r->id][] = sprintf('header: paid %.2f + due %.2f != grand total %.2f', $paid, $due, $grand);
                }

                if ((float) $r->allocated + (float) $r->written_off > $paid + self::TOLERANCE) {
                    $problems[(int) $r->id][] = sprintf(
                        'allocations %.2f + write-offs %.2f exceed header paid %.2f',
                        $r->allocated,
                        $r->written_off,
                        $paid
                    );
                }

                if ($r->sale_type === 'installment' && $r->plan_remaining !== null) {
                    if (abs((float) $r->schedule_remaining - $due) > self::TOLERANCE) {
                        $problems[(int) $r->id][] = sprintf('installment schedules remaining %.2f != invoice due %.2f', $r->schedule_remaining, $due);
                    }
                    if (abs((float) $r->plan_remaining - $due) > self::TOLERANCE) {
                        $problems[(int) $r->id][] = sprintf('plan remaining %.2f != invoice due %.2f', $r->plan_remaining, $due);
                    }
                }
            }
        });

        if ($problems === []) {
            $this->info('No integrity problems found.');

            return self::SUCCESS;
        }

        $missingNames = array_diff(array_keys($problems), array_keys($names));
        if ($missingNames !== []) {
            foreach (DB::table('sales_invoices')->whereIn('id', $missingNames)->pluck('invoice_number', 'id') as $id => $number) {
                $names[(int) $id] = $number;
            }
        }

        ksort($problems);
        $rows = [];
        foreach ($problems as $id => $messages) {
            foreach ($messages as $message) {
                $rows[] = [$id, $names[$id] ?? '—', $message];
            }
        }

        $this->table(['Invoice ID', 'Number', 'Problem'], $rows);
        $this->warn(count($problems).' invoice(s) with problems. Nothing was changed.');

        return $this->option('fail') ? self::FAILURE : self::SUCCESS;
    }
}
