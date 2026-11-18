<?php

namespace App\Console\Commands;

use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\InstallmentAdvanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RepairInstallmentAdvanceJournalCommand extends Command
{
    protected $signature = 'installments:repair-advance-journal
                            {--dry-run : Report repairs without saving}
                            {--invoice= : Limit to sales_invoices.id or invoice_number}
                            {--all : Include all months (default is current month only)}';

    protected $description = 'Backfill missing advance payments and undo advance-on-schedule rows for native (non-Dizicashier) installment invoices';

    public function handle(InstallmentAdvanceService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $invoiceOpt = $this->option('invoice');
        $actorUserId = $this->resolveActorUserId();

        $query = SalesInvoice::query()
            ->nativeSales()
            ->where('sale_type', 'installment')
            ->whereIn('status', ['active', 'posted'])
            ->where('installment_down_payment', '>', 0)
            ->with(['installmentPlan.schedules'])
            ->orderBy('id');

        $scopedToThisMonth = ! (bool) $this->option('all')
            && ($invoiceOpt === null || (string) $invoiceOpt === '');

        if ($scopedToThisMonth) {
            $monthStart = Carbon::now()->startOfMonth()->toDateString();
            $monthEnd = Carbon::now()->endOfMonth()->toDateString();
            $query->whereDate('invoice_date', '>=', $monthStart)
                ->whereDate('invoice_date', '<=', $monthEnd);
        }

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
                $diziQuery = SalesInvoice::query()
                    ->where('sale_type', 'installment')
                    ->whereIn('status', ['active', 'posted'])
                    ->whereNotNull('dizicashier_uuid')
                    ->where('dizicashier_uuid', '!=', '');

                if (is_numeric($needle)) {
                    $diziQuery->where(function ($q) use ($needle): void {
                        $q->whereKey((int) $needle)
                            ->orWhere('invoice_number', $needle);
                    });
                } else {
                    $diziQuery->where('invoice_number', $needle);
                }

                if ($diziQuery->exists()) {
                    $this->warn('Invoice '.$needle.' is a Dizicashier import — skipped. Use dizicash:repair-sale-payments instead.');

                    return self::SUCCESS;
                }

                $this->error('No matching native installment invoice found for: '.$needle);

                return self::FAILURE;
            }
        }

        $invoices = $query->get();
        if ($invoices->isEmpty()) {
            $scope = $scopedToThisMonth
                ? ' for '.Carbon::now()->format('F Y')
                : '';
            $this->info('No native installment invoices with advance need repair'.$scope.'.');

            return self::SUCCESS;
        }

        if ($scopedToThisMonth) {
            $this->info('Scope: invoices dated '.Carbon::now()->format('F Y').'.');
        }

        $created = 0;
        $posted = 0;
        $dateUpdated = 0;
        $schedulesRepaired = 0;
        $skipped = 0;

        foreach ($invoices as $invoice) {
            $downPayment = round((float) $invoice->installment_down_payment, 2);
            $existing = $service->findAdvancePayment($invoice);
            $hasJournal = $existing ? $service->advanceJournalExists($existing) : false;

            if ($dryRun) {
                if ($existing && $hasJournal) {
                    $targetDate = $service->advancePaymentDate($invoice)->toDateString();
                    $currentDate = $existing->payment_date->toDateString();
                    if ($currentDate !== $targetDate) {
                        $this->line("Would align advance dates for invoice #{$invoice->id} {$invoice->invoice_number} to {$targetDate}.");
                        $dateUpdated++;
                    } else {
                        $skipped++;
                        $this->line("Skip invoice #{$invoice->id} {$invoice->invoice_number}: advance journal already aligned.");
                    }
                } elseif ($existing && ! $hasJournal) {
                    $this->line("Would post advance journal for invoice #{$invoice->id} {$invoice->invoice_number} (payment #{$existing->id}).");
                    $posted++;
                } else {
                    $this->line("Would create advance payment + journal for invoice #{$invoice->id} {$invoice->invoice_number} ({$downPayment}).");
                    $created++;
                }

                $plan = $invoice->installmentPlan;
                if ($plan && $service->hasMisallocatedAdvanceOnSchedules($plan)) {
                    $repairPreview = $service->repairMisallocatedAdvanceOnSchedules($plan, dryRun: true);
                    $this->line("Would undo {$repairPreview['removed_allocations']} advance allocation(s) on invoice #{$invoice->id} {$invoice->invoice_number}.");
                    $schedulesRepaired++;
                }

                continue;
            }

            $plan = $invoice->installmentPlan;
            if ($plan && $service->hasMisallocatedAdvanceOnSchedules($plan)) {
                $scheduleRepair = $service->repairMisallocatedAdvanceOnSchedules($plan->fresh(['schedules', 'salesInvoice']));
                if ($scheduleRepair['repaired']) {
                    $schedulesRepaired++;
                    $this->info(
                        "Removed {$scheduleRepair['removed_allocations']} advance allocation(s) from installment rows on invoice #{$invoice->id} {$invoice->invoice_number}.",
                    );
                }
            }

            $result = $service->backfillMissingAdvancePayment($invoice->fresh(['installmentPlan.schedules']), $actorUserId);

            if ($result['created']) {
                $created++;
                $this->info("Created advance payment for invoice #{$invoice->id} {$invoice->invoice_number}.");
            }

            if ($result['journal_posted']) {
                $posted++;
                $this->info("Posted advance journal for invoice #{$invoice->id} {$invoice->invoice_number}.");
            } elseif (($result['reason'] ?? null) === 'journal_date_updated') {
                $dateUpdated++;
                $this->info("Aligned advance journal date for invoice #{$invoice->id} {$invoice->invoice_number}.");
            } elseif ($result['skipped']) {
                $skipped++;
                $this->warn("Skipped invoice #{$invoice->id} {$invoice->invoice_number}: ".($result['reason'] ?? 'unknown'));
            }
        }

        $this->newLine();
        $this->info(($dryRun ? 'Would create' : 'Created')." {$created} advance payment(s).");
        $this->info(($dryRun ? 'Would post' : 'Posted')." {$posted} advance journal(s).");
        $this->info(($dryRun ? 'Would align' : 'Aligned')." {$dateUpdated} advance journal date(s).");
        $this->info(($dryRun ? 'Would repair' : 'Repaired')." {$schedulesRepaired} invoice(s) with advance on schedule rows.");
        $this->info("Skipped {$skipped} invoice(s).");

        return self::SUCCESS;
    }

    private function resolveActorUserId(): int
    {
        $user = User::query()->where('is_active', true)->orderBy('id')->first();

        return (int) ($user?->id ?? 1);
    }
}
