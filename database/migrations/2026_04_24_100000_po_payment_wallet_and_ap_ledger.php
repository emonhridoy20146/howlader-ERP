<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ledger_accounts')) {
            $now = now();
            DB::table('ledger_accounts')->insertOrIgnore([
                [
                    'code' => '2100-AP',
                    'name' => 'Accounts payable — trade',
                    'type' => 'liability',
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }

        if (Schema::hasTable('purchase_order_payments') && ! Schema::hasColumn('purchase_order_payments', 'wallet_id')) {
            Schema::table('purchase_order_payments', function (Blueprint $table) {
                $table->foreignId('wallet_id')->nullable()->after('purchase_order_id')->constrained('wallets')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_order_payments', 'wallet_id')) {
            Schema::table('purchase_order_payments', function (Blueprint $table) {
                $table->dropForeign(['wallet_id']);
                $table->dropColumn('wallet_id');
            });
        }

        if (Schema::hasTable('ledger_accounts')) {
            DB::table('ledger_accounts')->where('code', '2100-AP')->delete();
        }
    }
};
