<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Alias for {@see InventoryBalanceCommand}: refreshes `inventory_balances` from `stock_ledger` (and serial reservations).
 */
class SyncInventoryBalancesCommand extends Command
{
    protected $signature = 'inventory:sync-balances
                            {--product= : Limit to a single product ID}
                            {--location= : Limit to a single location ID}
                            {--breakdown : Show ledger contribution by movement type}
                            {--dry-run : Show what would run without updating inventory_balances}';

    protected $description = 'Sync inventory_balances rows with quantities derived from the stock ledger';

    public function handle(): int
    {
        $options = [];
        foreach (['product', 'location', 'breakdown', 'dry-run'] as $name) {
            $key = $name === 'dry-run' ? '--dry-run' : '--'.$name;
            $value = $this->option($name);
            if ($name === 'breakdown' || $name === 'dry-run') {
                if ($value) {
                    $options[$key] = true;
                }
            } elseif ($value !== null && $value !== '') {
                $options[$key] = $value;
            }
        }

        return $this->call('inventory:balance', $options);
    }
}
