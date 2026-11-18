<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->decimal('settlement_discount', 12, 2)->default(0)->after('allocated_amount');
        });

        if (Schema::hasTable('ledger_accounts')) {
            $now = now();
            DB::table('ledger_accounts')->insertOrIgnore([
                [
                    'code' => '6180-PAYMENT-DISCOUNT',
                    'name' => 'Payment settlement discounts',
                    'type' => 'expense',
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_allocations') && Schema::hasColumn('payment_allocations', 'settlement_discount')) {
            Schema::table('payment_allocations', function (Blueprint $table): void {
                $table->dropColumn('settlement_discount');
            });
        }

        if (Schema::hasTable('ledger_accounts')) {
            DB::table('ledger_accounts')->where('code', '6180-PAYMENT-DISCOUNT')->delete();
        }
    }
};
