<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->date('credit_due_date')->nullable()->after('sale_type');
            $table->decimal('installment_down_payment', 12, 2)->nullable()->after('credit_due_date');
            $table->unsignedSmallInteger('installment_count')->nullable()->after('installment_down_payment');
            $table->date('installment_first_due_date')->nullable()->after('installment_count');
            $table->boolean('installment_penalty_enabled')->default(false)->after('installment_first_due_date');
            $table->decimal('installment_penalty_rate', 8, 4)->nullable()->after('installment_penalty_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'credit_due_date',
                'installment_down_payment',
                'installment_count',
                'installment_first_due_date',
                'installment_penalty_enabled',
                'installment_penalty_rate',
            ]);
        });
    }
};
