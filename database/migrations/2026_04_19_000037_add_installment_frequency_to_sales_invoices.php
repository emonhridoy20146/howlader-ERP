<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('installment_frequency', 20)->nullable()->after('installment_first_due_date');
            $table->unsignedInteger('installment_custom_days')->nullable()->after('installment_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn(['installment_frequency', 'installment_custom_days']);
        });
    }
};
