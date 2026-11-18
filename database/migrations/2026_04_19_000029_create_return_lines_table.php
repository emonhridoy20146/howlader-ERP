<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_order_id')->constrained('return_orders')->cascadeOnDelete();
            $table->foreignId('sales_invoice_line_id')->constrained('sales_invoice_lines');
            $table->foreignId('serial_item_id')->constrained('serial_items');
            $table->enum('resolution', ['restock', 'repair', 'replace', 'scrap']);
            $table->text('condition_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_lines');
    }
};
