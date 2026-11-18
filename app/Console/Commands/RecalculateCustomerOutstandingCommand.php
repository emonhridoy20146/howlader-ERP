<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\SalesInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RecalculateCustomerOutstandingCommand extends Command
{
    private const CACHE_KEY = 'reports.customer_dues.last_recalc';

    protected $signature = 'customers:recalculate-outstanding
                            {--dry-run : Compute differences without saving}
                            {--customer= : Limit to a single customers.id}';

    protected $description = 'Set each customer outstanding_balance to the sum of amount_due on posted/active sales invoices';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $customerOpt = $this->option('customer');
        $onlyCustomerId = is_numeric($customerOpt) ? (int) $customerOpt : null;

        if ($onlyCustomerId !== null && ! Customer::query()->whereKey($onlyCustomerId)->exists()) {
            $this->error('Customer not found: '.$onlyCustomerId);

            return self::FAILURE;
        }

        $computedByCustomer = SalesInvoice::query()
            ->whereIn('status', ['posted', 'active'])
            ->when($onlyCustomerId !== null, fn ($q) => $q->where('customer_id', $onlyCustomerId))
            ->selectRaw('customer_id, ROUND(COALESCE(SUM(amount_due), 0), 2) as computed')
            ->groupBy('customer_id')
            ->pluck('computed', 'customer_id');

        $customerIds = $onlyCustomerId !== null
            ? collect([$onlyCustomerId])
            : $computedByCustomer->keys()
                ->merge(
                    Customer::query()
                        ->where('outstanding_balance', '>', 0.009)
                        ->pluck('id'),
                )
                ->unique()
                ->values();

        $openInvoiceCount = (int) SalesInvoice::query()
            ->whereIn('status', ['posted', 'active'])
            ->where('amount_due', '>', 0.009)
            ->when($onlyCustomerId !== null, fn ($q) => $q->where('customer_id', $onlyCustomerId))
            ->count();

        $customers = Customer::query()
            ->whereIn('id', $customerIds->all())
            ->get(['id', 'customer_code', 'name', 'outstanding_balance']);

        $changes = [];
        $totalAbsDelta = 0.0;

        foreach ($customers as $c) {
            $newBalance = round((float) ($computedByCustomer->get($c->id) ?? 0), 2);
            $oldBalance = round((float) $c->outstanding_balance, 2);
            if (abs($oldBalance - $newBalance) <= 0.001) {
                continue;
            }
            $delta = $newBalance - $oldBalance;
            $totalAbsDelta += abs($delta);
            $changes[] = [
                'id' => (int) $c->id,
                'code' => (string) $c->customer_code,
                'name' => (string) $c->name,
                'before' => $oldBalance,
                'after' => $newBalance,
            ];
        }

        $portfolioBefore = (float) Customer::query()
            ->when($onlyCustomerId !== null, fn ($q) => $q->whereKey($onlyCustomerId))
            ->sum('outstanding_balance');

        if (! $dryRun && $changes !== []) {
            DB::transaction(function () use ($changes): void {
                foreach ($changes as $row) {
                    Customer::query()->whereKey($row['id'])->update([
                        'outstanding_balance' => $row['after'],
                    ]);
                }
            });
        }

        $sumDelta = array_sum(array_map(
            static fn (array $c): float => $c['after'] - $c['before'],
            $changes,
        ));
        $portfolioAfter = $dryRun
            ? round($portfolioBefore + $sumDelta, 2)
            : (float) Customer::query()
                ->when($onlyCustomerId !== null, fn ($q) => $q->whereKey($onlyCustomerId))
                ->sum('outstanding_balance');

        $this->info('Customers considered: '.$customers->count());
        $this->info('Open invoices (amount due > 0): '.$openInvoiceCount);
        $this->info('Customers adjusted: '.count($changes));
        $this->info('Σ outstanding '.($onlyCustomerId ? '(scoped) ' : '').'before: '.number_format($portfolioBefore, 2));
        $this->info('Σ outstanding '.($onlyCustomerId ? '(scoped) ' : '').'after:  '.number_format($portfolioAfter, 2));
        if ($changes !== []) {
            $this->info('Sum of |Δ| on changed rows: '.number_format($totalAbsDelta, 2));
        }

        if ($changes !== [] && $this->output->isVerbose()) {
            $this->table(
                ['ID', 'Code', 'Customer', 'Before', 'After'],
                collect($changes)->map(fn (array $c): array => [
                    $c['id'],
                    $c['code'],
                    $c['name'],
                    number_format($c['before'], 2),
                    number_format($c['after'], 2),
                ])->all(),
            );
        }

        if ($dryRun) {
            $this->comment($changes !== []
                ? 'Dry run — no customer rows updated. Run without --dry-run to apply.'
                : 'Dry run — all matching balances already match invoice totals.');
        } else {
            $this->info($changes !== []
                ? 'Updated '.count($changes).' customer balance(s).'
                : 'No updates required; balances already match invoice totals.');

            if ($onlyCustomerId === null) {
                $totalOutstandingAll = round((float) Customer::query()->sum('outstanding_balance'), 2);
                $customersWithDues = (int) Customer::query()
                    ->where('is_active', true)
                    ->where('outstanding_balance', '>', 0.009)
                    ->count();

                Cache::put(self::CACHE_KEY, [
                    'ran_at' => now()->toIso8601String(),
                    'customers_adjusted' => count($changes),
                    'customers_with_dues' => $customersWithDues,
                    'total_outstanding_all_customers' => $totalOutstandingAll,
                    'open_invoices' => $openInvoiceCount,
                ], now()->addDays(365));
            }
        }

        return self::SUCCESS;
    }
}
