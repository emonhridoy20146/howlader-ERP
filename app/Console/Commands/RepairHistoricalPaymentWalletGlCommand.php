<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\HistoricalPaymentWalletGlRepairService;
use Illuminate\Console\Command;

class RepairHistoricalPaymentWalletGlCommand extends Command
{
    protected $signature = 'payments:repair-historical-wallet-gl
                            {--dry-run : Report repairs without saving}
                            {--payment= : Limit to payments.id or payment_number}
                            {--invoice= : Limit to sales_invoices.id or invoice_number}';

    protected $description = 'Create wallet GL journal entries for historical payments that have none yet (skips payments that already have a journal entry)';

    public function handle(HistoricalPaymentWalletGlRepairService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $paymentOpt = $this->option('payment');
        $invoiceOpt = $this->option('invoice');
        $actorUserId = $this->resolveActorUserId();

        $query = $service->candidateQuery(
            is_string($paymentOpt) ? $paymentOpt : null,
            is_string($invoiceOpt) ? $invoiceOpt : null,
        );

        if ((is_string($paymentOpt) && $paymentOpt !== '') || (is_string($invoiceOpt) && $invoiceOpt !== '')) {
            if (! (clone $query)->exists()) {
                $this->error('No matching historical payment found for the given filter.');

                return self::FAILURE;
            }
        }

        $payments = $query->get()->filter(fn ($payment) => ! $payment->skipsWalletGl())->values();

        if ($payments->isEmpty()) {
            $this->info('No historical payments need wallet GL repair.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->comment('Dry run — no journals will be changed.');
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($payments as $payment) {
            $result = $service->repairPayment($payment, $actorUserId, $dryRun);
            $label = $payment->payment_number.' (#'.$payment->id.')';

            match ($result['status']) {
                'created', 'would_create' => (function () use (&$created, $label, $result, $dryRun): void {
                    $created++;
                    $verb = $dryRun ? 'Would create' : 'Created';
                    $this->info("{$verb} wallet GL for {$label} ({$result['reason']}).");
                })(),
                'failed' => (function () use (&$failed, $label, $result): void {
                    $failed++;
                    $this->error("Failed {$label}: ".($result['reason'] ?? 'unknown'));
                })(),
                default => (function () use (&$skipped, $label, $result, $dryRun): void {
                    $skipped++;
                    if ($this->output->isVerbose()) {
                        $this->line("Skipped {$label}: ".($result['reason'] ?? 'unknown'));
                    }
                })(),
            };
        }

        $this->newLine();
        $this->info(($dryRun ? 'Would create' : 'Created')." {$created} payment(s).");
        $this->info("Skipped {$skipped} payment(s).");
        if ($failed > 0) {
            $this->warn("Failed {$failed} payment(s).");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveActorUserId(): int
    {
        $user = User::query()->where('is_active', true)->orderBy('id')->first();

        return (int) ($user?->id ?? 1);
    }
}
