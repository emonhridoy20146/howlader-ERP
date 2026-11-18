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
                'code' => '5310-PO-LOGISTICS',
                'name' => 'Purchase order — logistics & handling',
                'type' => 'expense',
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

        DB::table('ledger_accounts')->where('code', '5310-PO-LOGISTICS')->delete();
    }
};
