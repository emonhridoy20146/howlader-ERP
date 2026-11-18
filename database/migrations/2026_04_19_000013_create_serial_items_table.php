<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serial_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->string('serial_number', 100)->unique();
            $table->foreignId('current_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->enum('status', ['draft', 'in_stock', 'reserved', 'sold', 'returned', 'damaged', 'in_transfer', 'under_service'])->default('draft');
            $table->foreignId('goods_receipt_line_id')->nullable()->constrained('goods_receipt_lines')->nullOnDelete();
            $table->unsignedBigInteger('sale_invoice_line_id')->nullable();
            $table->date('warranty_start_date')->nullable();
            $table->date('warranty_end_date')->nullable();
            $table->decimal('purchase_cost', 12, 2)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'current_location_id', 'status']);
            $table->index('serial_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_items');
    }
};
