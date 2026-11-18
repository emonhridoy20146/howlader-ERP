<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_payments', function (Blueprint $table) {
            $table->decimal('settlement_discount', 12, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_payments', function (Blueprint $table) {
            $table->dropColumn('settlement_discount');
        });
    }
};
