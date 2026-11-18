<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dizicashier_imported_stock_transfers')) {
            Schema::drop('dizicashier_imported_stock_transfers');
        }

        if (Schema::hasTable('stock_transfers') && ! Schema::hasColumn('stock_transfers', 'dizicashier_uuid')) {
            Schema::table('stock_transfers', function (Blueprint $table): void {
                $table->uuid('dizicashier_uuid')->nullable()->after('id')->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_transfers') && Schema::hasColumn('stock_transfers', 'dizicashier_uuid')) {
            Schema::table('stock_transfers', function (Blueprint $table): void {
                $table->dropUnique(['dizicashier_uuid']);
                $table->dropColumn('dizicashier_uuid');
            });
        }
    }
};
