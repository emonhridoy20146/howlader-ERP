<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial_items', function (Blueprint $table) {
            $table->index('status', 'idx_serial_status');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['customer_id', 'payment_date'], 'idx_payment_customer');
        });
    }

    public function down(): void
    {
        Schema::table('serial_items', function (Blueprint $table) {
            $table->dropIndex('idx_serial_status');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payment_customer');
        });
    }
};
