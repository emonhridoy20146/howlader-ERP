<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('maximum_deductible', 5, 2)
                ->nullable()
                ->after('default_sale_price')
                ->comment('Max discount % allowed on invoices (nullable = use global default)');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('maximum_deductible');
        });
    }
};
