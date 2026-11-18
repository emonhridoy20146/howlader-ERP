<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_orders', function (Blueprint $table) {
            $table->id();
            $table->string('return_number', 30)->unique();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('branch_id')->constrained('locations');
            $table->enum('status', ['draft', 'approved', 'restocked', 'rejected', 'refunded'])->default('draft');
            $table->date('return_date');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_orders');
    }
};
