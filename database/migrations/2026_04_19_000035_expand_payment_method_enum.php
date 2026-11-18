<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE payments MODIFY payment_method ENUM('cash','card','bank_transfer','mobile_money','bank','credit','installment') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE payments SET payment_method='bank_transfer' WHERE payment_method='bank'");
        DB::statement("UPDATE payments SET payment_method='cash' WHERE payment_method IN ('credit','installment')");

        DB::statement("ALTER TABLE payments MODIFY payment_method ENUM('cash','card','bank_transfer','mobile_money') NOT NULL");
    }
};
