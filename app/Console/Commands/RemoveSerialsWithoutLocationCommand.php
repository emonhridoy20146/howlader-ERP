<?php

namespace App\Console\Commands;

use App\Models\SerialItem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RemoveSerialsWithoutLocationCommand extends Command
{
    protected $signature = 'inventory:remove-serials-without-location
                            {--dry-run : Preview deletions without making changes}
                            {--force : Delete even when linked to sale_line_serials, return_lines, or service_jobs}';

    protected $description = 'Delete serial items that have no branch (current_location_id is null); skips records still linked to sales unless --force';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $serials = SerialItem::query()
            ->whereNull('current_location_id')
            ->with('product:id,sku,name')
            ->orderBy('id')
            ->get();

        if ($serials->isEmpty()) {
            $this->info('No serial items without a location.');

            return self::SUCCESS;
        }

        $byStatus = $serials->groupBy('status')->map->count();
        $this->line(sprintf('Found %d serial(s) without location:', $serials->count()));
        foreach ($byStatus as $status => $count) {
            $this->line("  {$status}: {$count}");
        }

        [$toDelete, $skipped] = $this->partitionDeletable($serials, $force);

        if ($skipped->isNotEmpty()) {
            $this->newLine();
            $this->warn(sprintf('Skipping %d serial(s) still linked to other records (use --force to delete anyway):', $skipped->count()));
            foreach ($skipped as $serial) {
                $this->line(sprintf(
                    '  #%d %s (%s) — %s',
                    $serial->id,
                    $serial->serial_number,
                    $serial->status,
                    implode(', ', $serial->blockers),
                ));
            }
        }

        if ($toDelete->isEmpty()) {
            $this->newLine();
            $this->info('Nothing to delete.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf('%s %d serial(s).', $dryRun ? 'Would delete' : 'Deleting', $toDelete->count()));

        if ($dryRun) {
            return self::SUCCESS;
        }

        $deleted = 0;
        $transferLinksRemoved = 0;

        DB::transaction(function () use ($toDelete, &$deleted, &$transferLinksRemoved): void {
            foreach ($toDelete->chunk(100) as $chunk) {
                $ids = $chunk->pluck('id')->all();

                $transferLinksRemoved += DB::table('stock_transfer_serials')
                    ->whereIn('serial_item_id', $ids)
                    ->delete();

                if ($this->option('force')) {
                    DB::table('sale_line_serials')->whereIn('serial_item_id', $ids)->delete();
                    DB::table('return_lines')->whereIn('serial_item_id', $ids)->delete();
                    DB::table('service_jobs')->whereIn('serial_item_id', $ids)->delete();
                }

                $deleted += SerialItem::query()->whereIn('id', $ids)->delete();
            }
        });

        $this->info(sprintf('Deleted %d serial item(s); removed %d stock-transfer link(s).', $deleted, $transferLinksRemoved));
        $this->comment('Stock ledger rows for those serials keep their history with serial_item_id set to null.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: Collection<int, object>, 1: Collection<int, object>}
     */
    private function partitionDeletable(Collection $serials, bool $force): array
    {
        if ($force) {
            return [$serials->map(fn (SerialItem $s) => (object) ['id' => $s->id, 'serial_number' => $s->serial_number, 'status' => $s->status]), collect()];
        }

        $ids = $serials->pluck('id');
        $saleIds = DB::table('sale_line_serials')->whereIn('serial_item_id', $ids)->pluck('serial_item_id')->flip();
        $returnIds = DB::table('return_lines')->whereIn('serial_item_id', $ids)->pluck('serial_item_id')->flip();
        $serviceIds = DB::table('service_jobs')->whereIn('serial_item_id', $ids)->pluck('serial_item_id')->flip();

        $toDelete = collect();
        $skipped = collect();

        foreach ($serials as $serial) {
            $blockers = [];
            if ($saleIds->has($serial->id)) {
                $blockers[] = 'sale_line_serials';
            }
            if ($returnIds->has($serial->id)) {
                $blockers[] = 'return_lines';
            }
            if ($serviceIds->has($serial->id)) {
                $blockers[] = 'service_jobs';
            }

            if ($blockers !== []) {
                $serial->blockers = $blockers;
                $skipped->push($serial);

                continue;
            }

            $toDelete->push((object) [
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'status' => $serial->status,
            ]);
        }

        return [$toDelete, $skipped];
    }
}
