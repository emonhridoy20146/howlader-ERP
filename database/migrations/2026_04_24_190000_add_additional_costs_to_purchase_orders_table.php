<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('additional_cost_amount', 12, 2)->default(0)->after('tax_amount');
            $table->json('additional_costs')->nullable()->after('additional_cost_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['additional_cost_amount', 'additional_costs']);
        });
    }
};
