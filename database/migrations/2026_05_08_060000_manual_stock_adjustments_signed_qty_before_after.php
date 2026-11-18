<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual adjustments can start from a negative ledger total; UNSIGNED qty_before/qty_after reject those inserts on MySQL.
     */
    public function up(): void
    {
        if (! Schema::hasTable('manual_stock_adjustments')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE manual_stock_adjustments MODIFY qty_before INT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE manual_stock_adjustments MODIFY qty_after INT NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        if (! Schema::hasTable('manual_stock_adjustments')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('UPDATE manual_stock_adjustments SET qty_before = GREATEST(0, qty_before), qty_after = GREATEST(0, qty_after)');

        DB::statement('ALTER TABLE manual_stock_adjustments MODIFY qty_before INT UNSIGNED NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE manual_stock_adjustments MODIFY qty_after INT UNSIGNED NOT NULL DEFAULT 0');
    }
};
