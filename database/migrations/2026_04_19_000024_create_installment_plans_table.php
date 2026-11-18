<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices');
            $table->foreignId('customer_id')->constrained('customers');
            $table->decimal('total_amount', 12, 2);
            $table->decimal('down_payment', 12, 2)->default(0);
            $table->decimal('financed_amount', 12, 2);
            $table->decimal('remaining_amount', 12, 2);
            $table->boolean('penalty_enabled')->default(false);
            $table->decimal('penalty_rate', 8, 4)->default(0.02);
            $table->unsignedSmallInteger('reschedule_count')->default(0);
            $table->enum('status', ['active', 'completed', 'defaulted', 'cancelled'])->default('active');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_plans');
    }
};
