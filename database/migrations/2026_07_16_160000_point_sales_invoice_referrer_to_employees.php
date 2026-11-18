<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropForeign(['referrer_id']);
        });

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->foreign('referrer_id')
                ->references('id')
                ->on('employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropForeign(['referrer_id']);
        });

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->foreign('referrer_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
