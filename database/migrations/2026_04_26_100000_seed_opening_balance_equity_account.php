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
                'code' => '3900-OPENING-EQUITY',
                'name' => 'Wallet opening balance (equity offset)',
                'type' => 'equity',
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

        DB::table('ledger_accounts')->where('code', '3900-OPENING-EQUITY')->delete();
    }
};
