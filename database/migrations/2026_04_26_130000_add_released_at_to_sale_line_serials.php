<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sale_line_serials', 'released_at')) {
            Schema::table('sale_line_serials', function (Blueprint $table) {
                $table->timestamp('released_at')->nullable()->after('serial_item_id');
            });
        }

        $releasedAt = now();
        $pivotIds = DB::table('sale_line_serials as sls')
            ->join('return_lines as rl', function ($join): void {
                $join->on('rl.serial_item_id', '=', 'sls.serial_item_id')
                    ->on('rl.sales_invoice_line_id', '=', 'sls.sales_invoice_line_id');
            })
            ->join('return_orders as ro', 'ro.id', '=', 'rl.return_order_id')
            ->whereNull('sls.released_at')
            ->whereIn('ro.status', ['approved', 'refunded'])
            ->pluck('sls.id');

        if ($pivotIds->isNotEmpty()) {
            DB::table('sale_line_serials')->whereIn('id', $pivotIds->all())->update(['released_at' => $releasedAt]);
        }

        $indexNames = collect(Schema::getIndexes('sale_line_serials'))->pluck('name')->all();

        $hasOldUniqueOnSerial = in_array('sale_line_serials_serial_item_id_unique', $indexNames, true);

        if ($hasOldUniqueOnSerial) {
            Schema::table('sale_line_serials', function (Blueprint $table) {
                $table->dropForeign(['serial_item_id']);
            });

            Schema::table('sale_line_serials', function (Blueprint $table) {
                $table->dropUnique(['serial_item_id']);
            });

            Schema::table('sale_line_serials', function (Blueprint $table) {
                $table->index('serial_item_id');
            });

            Schema::table('sale_line_serials', function (Blueprint $table) {
                $table->foreign('serial_item_id')->references('id')->on('serial_items');
            });
        }

        $indexNames = collect(Schema::getIndexes('sale_line_serials'))->pluck('name')->all();

        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            if (! in_array('sale_line_serials_serial_item_id_active_unique', $indexNames, true)) {
                DB::statement('CREATE UNIQUE INDEX sale_line_serials_serial_item_id_active_unique ON sale_line_serials (serial_item_id) WHERE released_at IS NULL');
            }
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (! Schema::hasColumn('sale_line_serials', 'active_serial_item_id')) {
                Schema::table('sale_line_serials', function (Blueprint $table) {
                    $table->unsignedBigInteger('active_serial_item_id')->nullable()->storedAs(
                        'case when `released_at` is null then `serial_item_id` else null end'
                    );
                });
            }

            $indexNames = collect(Schema::getIndexes('sale_line_serials'))->pluck('name')->all();
            if (! in_array('sale_line_serials_active_serial_item_id_unique', $indexNames, true)) {
                Schema::table('sale_line_serials', function (Blueprint $table) {
                    $table->unique('active_serial_item_id', 'sale_line_serials_active_serial_item_id_unique');
                });
            }
        } elseif ($driver === 'pgsql') {
            if (! in_array('sale_line_serials_serial_item_id_active_unique', $indexNames, true)) {
                DB::statement('CREATE UNIQUE INDEX sale_line_serials_serial_item_id_active_unique ON sale_line_serials (serial_item_id) WHERE released_at IS NULL');
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        $indexNames = collect(Schema::getIndexes('sale_line_serials'))->pluck('name')->all();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS sale_line_serials_serial_item_id_active_unique');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (in_array('sale_line_serials_active_serial_item_id_unique', $indexNames, true)) {
                Schema::table('sale_line_serials', function (Blueprint $table) {
                    $table->dropUnique(['active_serial_item_id']);
                });
            }

            try {
                Schema::table('sale_line_serials', function (Blueprint $table) {
                    $table->dropIndex('sale_line_serials_serial_item_id_active_unique');
                });
            } catch (Throwable) {
                // Expression index from older migration revision may not exist (e.g. MariaDB).
            }

            if (Schema::hasColumn('sale_line_serials', 'active_serial_item_id')) {
                Schema::table('sale_line_serials', function (Blueprint $table) {
                    $table->dropColumn('active_serial_item_id');
                });
            }
        } else {
            DB::statement('DROP INDEX IF EXISTS sale_line_serials_serial_item_id_active_unique');
        }

        Schema::table('sale_line_serials', function (Blueprint $table) {
            $table->dropForeign(['serial_item_id']);
        });

        Schema::table('sale_line_serials', function (Blueprint $table) {
            $table->dropIndex(['serial_item_id']);
        });

        DB::table('sale_line_serials')->whereNotNull('released_at')->delete();

        if (Schema::hasColumn('sale_line_serials', 'released_at')) {
            Schema::table('sale_line_serials', function (Blueprint $table) {
                $table->dropColumn('released_at');
            });
        }

        Schema::table('sale_line_serials', function (Blueprint $table) {
            $table->unique('serial_item_id');
        });

        Schema::table('sale_line_serials', function (Blueprint $table) {
            $table->foreign('serial_item_id')->references('id')->on('serial_items');
        });
    }
};
