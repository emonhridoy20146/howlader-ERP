<?php

namespace App\Console\Commands;

use App\Models\StockLedger;
use App\Services\InventoryBalanceService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileSerialSalesLedgerCommand extends Command
{
    protected $signature = 'i   
                            {--dry-run : List missing ledger rows without inserting or recalculating balances}
                            {--product= : Only serials for this product ID}
                            {--location= : Only sales at this invoice branch (location) ID}';

    protected $description = 'Backfill missing sale_issue stock_ledger rows for sold serials on posted/active invoices, then recalculate inventory_balances';

    public function handle(InventoryBalanceService $inventoryBalanceService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyProductId = $this->parseOptionalIntOption('product');
        $onlyLocationId = $this->parseOptionalIntOption('location');

        $query = DB::table('serial_items as si')
            ->join('sales_invoice_lines as sil', 'sil.id', '=', 'si.sale_invoice_line_id')
            ->join('sales_invoices as inv', 'inv.id', '=', 'sil.sales_invoice_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->where('si.status', 'sold')
            ->whereNotNull('si.sale_invoice_line_id')
            ->where('p.is_serialized', true)
            ->whereIn('inv.status', ['posted', 'active'])
            ->whereRaw(
                'COALESCE((SELECT SUM(sl.quantity_delta) FROM stock_ledger as sl WHERE sl.serial_item_id = si.id AND sl.movement_type = ?), 0) >= 0',
                ['sale_issue']
            )
            ->select([
                'si.id as serial_item_id',
                'si.product_id',
                'si.purchase_cost',
                'inv.id as sales_invoice_id',
                'inv.branch_id',
                'inv.posted_at',
                'inv.invoice_date',
                'inv.invoice_number',
                'inv.posted_by',
                'inv.created_by',
            ]);

        if ($onlyProductId !== null) {
            $query->where('si.product_id', $onlyProductId);
        }
        if ($onlyLocationId !== null) {
            $query->where('inv.branch_id', $onlyLocationId);
        }

        $rows = $query->orderBy('si.id')->get();

        if ($rows->isEmpty()) {
            $this->info('No sold serials need sale_issue ledger backfill.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('Found %d sold serial(s) with no net sale_issue quantity in the ledger.', $rows->count()));

        $recalcKeys = [];

        $apply = function () use ($rows, &$recalcKeys): void {
            foreach ($rows as $row) {
                $recalcKeys[(int) $row->product_id.'|'.(int) $row->branch_id] = [
                    'product_id' => (int) $row->product_id,
                    'location_id' => (int) $row->branch_id,
                ];

                if ($this->output->isVerbose()) {
                    $this->line(sprintf(
                        '  serial_item_id=%d product_id=%d invoice=%s (#%d) branch_id=%d',
                        (int) $row->serial_item_id,
                        (int) $row->product_id,
                        (string) $row->invoice_number,
                        (int) $row->sales_invoice_id,
                        (int) $row->branch_id
                    ));
                }

                $eventTime = $row->posted_at
                    ? Carbon::parse($row->posted_at)
                    : Carbon::parse($row->invoice_date)->startOfDay();

                StockLedger::query()->create([
                    'event_time' => $eventTime,
                    'product_id' => (int) $row->product_id,
                    'serial_item_id' => (int) $row->serial_item_id,
                    'location_id' => (int) $row->branch_id,
                    'movement_type' => 'sale_issue',
                    'quantity_delta' => -1,
                    'unit_cost' => $row->purchase_cost !== null ? (float) $row->purchase_cost : null,
                    'reference_type' => 'SalesInvoice',
                    'reference_id' => (int) $row->sales_invoice_id,
                    'actor_user_id' => (int) ($row->posted_by ?? $row->created_by),
                ]);
            }
        };

        if ($dryRun) {
            foreach ($rows as $row) {
                if ($this->output->isVerbose()) {
                    $this->line(sprintf(
                        '  serial_item_id=%d product_id=%d invoice=%s (#%d) branch_id=%d',
                        (int) $row->serial_item_id,
                        (int) $row->product_id,
                        (string) $row->invoice_number,
                        (int) $row->sales_invoice_id,
                        (int) $row->branch_id
                    ));
                }
            }

            $this->newLine();
            $this->comment('Dry run: no ledger rows inserted and balances unchanged. Omit --dry-run to apply.');
        } else {
            DB::transaction($apply);

            foreach ($recalcKeys as $pair) {
                $inventoryBalanceService->recalculate($pair['product_id'], $pair['location_id']);
            }
            $this->newLine();
            $this->info('Inserted '.$rows->count().' sale_issue row(s) and refreshed inventory_balances for affected branches.');
        }

        return self::SUCCESS;
    }

    private function parseOptionalIntOption(string $name): ?int
    {
        $raw = $this->option($name);
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }
}
