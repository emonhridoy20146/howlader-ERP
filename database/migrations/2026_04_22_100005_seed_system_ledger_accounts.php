<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ledger_accounts')) {
            return;
        }

        $now = now();
        DB::table('ledger_accounts')->insertOrIgnore([
            [
                'code' => '1200-AR',
                'name' => 'Accounts receivable — trade',
                'type' => 'asset',
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => '4100-SALES',
                'name' => 'Sales revenue',
                'type' => 'income',
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('ledger_accounts')) {
            return;
        }

        DB::table('ledger_accounts')->whereIn('code', ['1200-AR', '4100-SALES'])->delete();
    }
};
