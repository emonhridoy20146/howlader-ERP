<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'locations',
        'customers',
        'suppliers',
        'products',
        'sales_invoices',
        'purchase_orders',
        'goods_receipts',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'dizicashier_uuid')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('dizicashier_uuid', 36)->nullable()->after('id');
            });
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unique('dizicashier_uuid');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'dizicashier_uuid')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropUnique(['dizicashier_uuid']);
            });
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('dizicashier_uuid');
            });
        }
    }
};
