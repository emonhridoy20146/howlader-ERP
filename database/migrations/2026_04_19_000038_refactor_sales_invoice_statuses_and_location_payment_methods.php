<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('locations', 'allowed_payment_methods')) {
            Schema::table('locations', function (Blueprint $table): void {
                $table->json('allowed_payment_methods')->nullable()->after('is_active');
            });
        }

        if (DB::getDriverName() !== 'mysql') {
            if (! Schema::hasColumn('sales_invoices', 'payment_status')) {
                Schema::table('sales_invoices', function (Blueprint $table): void {
                    $table->string('payment_status', 20)->default('unpaid')->after('status');
                });
            }

            DB::table('sales_invoices')->where('sale_type', 'cash')->update(['sale_type' => 'default']);
            DB::table('sales_invoices')
                ->whereIn('status', ['partially_paid', 'paid'])
                ->update(['status' => 'posted']);

            DB::table('sales_invoices')->where('amount_due', '<=', 0)->update(['payment_status' => 'paid']);
            DB::table('sales_invoices')
                ->where('amount_due', '>', 0)
                ->where('amount_paid', '>', 0)
                ->update(['payment_status' => 'partial']);

            return;
        }

        if (! Schema::hasColumn('sales_invoices', 'payment_status')) {
            DB::statement("ALTER TABLE sales_invoices ADD COLUMN payment_status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid' AFTER status");
        }

        DB::statement("ALTER TABLE sales_invoices MODIFY sale_type ENUM('cash','default','credit','installment') NOT NULL DEFAULT 'cash'");
        DB::statement("UPDATE sales_invoices SET sale_type='default' WHERE sale_type='cash'");
        DB::statement("UPDATE sales_invoices SET status='posted' WHERE status IN ('partially_paid','paid')");
        DB::statement("UPDATE sales_invoices SET payment_status='paid' WHERE amount_due <= 0");
        DB::statement("UPDATE sales_invoices SET payment_status='partial' WHERE amount_due > 0 AND amount_paid > 0");

        DB::statement("ALTER TABLE sales_invoices MODIFY sale_type ENUM('default','credit','installment') NOT NULL DEFAULT 'default'");
        DB::statement("ALTER TABLE sales_invoices MODIFY status ENUM('draft','active','posted','cancelled','returned') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales_invoices MODIFY sale_type ENUM('cash','default','credit','installment') NOT NULL DEFAULT 'default'");
            DB::statement("UPDATE sales_invoices SET sale_type='cash' WHERE sale_type='default'");
            DB::statement("UPDATE sales_invoices SET status='partially_paid' WHERE payment_status='partial'");
            DB::statement("UPDATE sales_invoices SET status='paid' WHERE payment_status='paid'");
            DB::statement("ALTER TABLE sales_invoices MODIFY sale_type ENUM('cash','credit','installment') NOT NULL DEFAULT 'cash'");
            DB::statement("ALTER TABLE sales_invoices MODIFY status ENUM('draft','posted','partially_paid','paid','cancelled','returned') NOT NULL DEFAULT 'draft'");
            if (Schema::hasColumn('sales_invoices', 'payment_status')) {
                DB::statement('ALTER TABLE sales_invoices DROP COLUMN payment_status');
            }
        } else {
            if (Schema::hasColumn('sales_invoices', 'payment_status')) {
                Schema::table('sales_invoices', function (Blueprint $table): void {
                    $table->dropColumn('payment_status');
                });
            }
        }

        if (Schema::hasColumn('locations', 'allowed_payment_methods')) {
            Schema::table('locations', function (Blueprint $table): void {
                $table->dropColumn('allowed_payment_methods');
            });
        }
    }
};
