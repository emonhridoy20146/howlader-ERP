<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock ledger sums can go negative during historical imports (e.g. sale before receive
     * at the same location). UNSIGNED columns reject those writes; use signed INT instead.
     */
    public function up(): void
    {
        if (! Schema::hasTable('inventory_balances')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE inventory_balances MODIFY on_hand_qty INT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE inventory_balances MODIFY reserved_qty INT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE inventory_balances MODIFY available_qty INT NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        if (! Schema::hasTable('inventory_balances')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('UPDATE inventory_balances SET on_hand_qty = GREATEST(0, on_hand_qty), reserved_qty = GREATEST(0, reserved_qty), available_qty = GREATEST(0, available_qty)');

        DB::statement('ALTER TABLE inventory_balances MODIFY on_hand_qty INT UNSIGNED NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE inventory_balances MODIFY reserved_qty INT UNSIGNED NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE inventory_balances MODIFY available_qty INT UNSIGNED NOT NULL DEFAULT 0');
    }
};
