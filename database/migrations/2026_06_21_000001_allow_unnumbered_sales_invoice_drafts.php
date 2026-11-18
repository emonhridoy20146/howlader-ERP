<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE sales_invoices MODIFY invoice_number VARCHAR(30) NULL');

            return;
        }

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->string('invoice_number', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('sales_invoices')
            ->whereNull('invoice_number')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $invoice): void {
                DB::table('sales_invoices')
                    ->where('id', $invoice->id)
                    ->update(['invoice_number' => 'DRAFT-'.$invoice->id]);
            });

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE sales_invoices MODIFY invoice_number VARCHAR(30) NOT NULL');

            return;
        }

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->string('invoice_number', 30)->nullable(false)->change();
        });
    }
};
