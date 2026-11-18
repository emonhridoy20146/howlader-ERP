<?php

namespace App\Console\Commands;

use App\Models\SerialItem;
use App\Support\SerialLabelCode;
use Illuminate\Console\Command;

class AssignSerialLabelCodesCommand extends Command
{
    protected $signature = 'serials:assign-label-codes
                            {--dry-run : Show how many rows would be updated}';

    protected $description = 'Assign compact 5-digit label codes to serial items missing one';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $missing = SerialItem::query()->whereNull('label_code')->count();

        if ($missing === 0) {
            $this->info('All serial items already have a label code.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Would assign label codes to {$missing} serial item(s).");

            return self::SUCCESS;
        }

        $next = SerialLabelCode::maxAssignedCode();
        $assigned = 0;

        SerialItem::query()
            ->whereNull('label_code')
            ->orderBy('id')
            ->chunkById(200, function ($serials) use (&$next, &$assigned): void {
                foreach ($serials as $serial) {
                    $next++;
                    if ($next > SerialLabelCode::maxValue()) {
                        throw new \RuntimeException('Serial label code range exhausted while backfilling.');
                    }

                    $serial->label_code = $next;
                    $serial->save();
                    $assigned++;
                }
            });

        $this->info("Assigned {$assigned} label code(s).");

        return self::SUCCESS;
    }
}
