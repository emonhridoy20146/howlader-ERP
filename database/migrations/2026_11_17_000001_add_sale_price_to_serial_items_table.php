<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial_items', function (Blueprint $table): void {
            // Optional per-unit sell price; when null the product's default sale price applies.
            $table->decimal('sale_price', 12, 2)->nullable()->after('purchase_cost');
        });
    }

    public function down(): void
    {
        Schema::table('serial_items', function (Blueprint $table): void {
            $table->dropColumn('sale_price');
        });
    }
};
