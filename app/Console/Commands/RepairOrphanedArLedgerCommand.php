<?php

namespace App\Console\Commands;

use App\Models\AccountsReceivableLedger;
use App\Models\Payment;
use App\Models\SalesInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairOrphanedArLedgerCommand extends Command
{
    /**
     * Historically, accounts_receivable_ledger.reference_type was written as the short
     * class basename ('SalesInvoice', 'Payment') rather than the fully-qualified class
     * name. Several cleanup paths (SalesInvoiceController::destroy, PaymentService
     * payment reversal) deleted ledger rows by matching only the FQCN, so those deletes
     * silently matched nothing and left the ledger row behind referencing an invoice or
     * payment that no longer exists. Those bugs are now fixed, but the orphaned rows
     * they already produced are still sitting in the ledger, inflating (or deflating)
     * customers.outstanding_balance whenever it gets recomputed from the ledger.
     *
     * This command finds and removes those orphans, then recomputes outstanding_balance
     * for every affected customer from the sum of amount_due on their posted/active
     * sales invoices (the same formula customers:recalculate-outstanding uses).
     */
    protected $signature = 'ar-ledger:repair-orphans
                            {--dry-run : Report what would change without saving}
                            {--customer= : Limit to a single customers.id}';

    protected $description = 'Delete accounts_receivable_ledger rows left behind by deleted invoices/payments, then fix affected customers\' outstanding_balance';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $customerOpt = $this->option('customer');
        $onlyCustomerId = is_numeric($customerOpt) ? (int) $customerOpt : null;

        $invoiceOrphans = DB::table('accounts_receivable_ledger as ar')
            ->leftJoin('sales_invoices as si', 'si.id', '=', 'ar.reference_id')
            ->whereIn('ar.reference_type', ['SalesInvoice', SalesInvoice::class])
            ->whereNull('si.id')
            ->when($onlyCustomerId !== null, fn ($q) => $q->where('ar.customer_id', $onlyCustomerId))
            ->select('ar.id', 'ar.customer_id', 'ar.reference_id', 'ar.amount_delta')
            ->get();

        $paymentOrphans = DB::table('accounts_receivable_ledger as ar')
            ->leftJoin('payments as p', 'p.id', '=', 'ar.reference_id')
            ->whereIn('ar.reference_type', ['Payment', Payment::class])
            ->whereNull('p.id')
            ->when($onlyCustomerId !== null, fn ($q) => $q->where('ar.customer_id', $onlyCustomerId))
            ->select('ar.id', 'ar.customer_id', 'ar.reference_id', 'ar.amount_delta')
            ->get();

        $affectedCustomerIds = $invoiceOrphans->pluck('customer_id')
            ->merge($paymentOrphans->pluck('customer_id'))
            ->unique()
            ->values();

        $this->info('Orphaned invoice-posting ledger rows: '.$invoiceOrphans->count().' (Σ '.number_format($invoiceOrphans->sum('amount_delta'), 2).')');
        $this->info('Orphaned payment-allocation ledger rows: '.$paymentOrphans->count().' (Σ '.number_format($paymentOrphans->sum('amount_delta'), 2).')');
        $this->info('Customers affected: '.$affectedCustomerIds->count());

        if ($affectedCustomerIds->isEmpty()) {
            $this->comment('Nothing to repair.');

            return self::SUCCESS;
        }

        if ($this->output->isVerbose()) {
            $rows = $invoiceOrphans->map(fn ($o) => ['ledger_id' => $o->id, 'customer_id' => $o->customer_id, 'type' => 'invoice', 'ref_id' => $o->reference_id, 'amount_delta' => number_format($o->amount_delta, 2)])
                ->merge($paymentOrphans->map(fn ($o) => ['ledger_id' => $o->id, 'customer_id' => $o->customer_id, 'type' => 'payment', 'ref_id' => $o->reference_id, 'amount_delta' => number_format($o->amount_delta, 2)]));
            $this->table(['Ledger ID', 'Customer', 'Type', 'Ref ID', 'Amount Δ'], $rows->all());
        }

        $ledgerIds = $invoiceOrphans->pluck('id')->merge($paymentOrphans->pluck('id'))->values();

        if ($dryRun) {
            $this->comment('Dry run — no ledger rows deleted. Run without --dry-run to apply, then it will also recompute outstanding_balance for affected customers.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($ledgerIds): void {
            AccountsReceivableLedger::query()->whereIn('id', $ledgerIds->all())->delete();
        });

        $this->info('Deleted '.$ledgerIds->count().' orphaned ledger row(s).');

        foreach ($affectedCustomerIds as $customerId) {
            $this->call('customers:recalculate-outstanding', ['--customer' => $customerId]);
        }

        return self::SUCCESS;
    }
}
