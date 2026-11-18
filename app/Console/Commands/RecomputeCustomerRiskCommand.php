<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\CustomerRiskService;
use Illuminate\Console\Command;
use Throwable;

class RecomputeCustomerRiskCommand extends Command
{
    protected $signature = 'customers:recompute-risk
                            {--customer= : Limit to a single customers.id}
                            {--chunk=200 : Customers per chunk}';

    protected $description = 'Recompute stored customer risk snapshots (days late grow even without payment events)';

    public function handle(CustomerRiskService $service): int
    {
        $query = Customer::query()->orderBy('id');
        if (is_numeric($this->option('customer'))) {
            $query->whereKey((int) $this->option('customer'));
        }

        $total = (clone $query)->count();
        $bar = $this->output->createProgressBar($total);
        $failed = 0;

        $query->chunkById(max(1, (int) $this->option('chunk')), function ($customers) use ($service, $bar, &$failed): void {
            foreach ($customers as $customer) {
                try {
                    $service->refreshSnapshot($customer);
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Recomputed '.($total - $failed).' of '.$total.' customer risk snapshots.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
