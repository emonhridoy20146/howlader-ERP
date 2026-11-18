<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_line_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_line_id')->constrained('sales_invoice_lines')->cascadeOnDelete();
            $table->foreignId('serial_item_id')->constrained('serial_items');
            $table->timestamps();

            $table->unique('serial_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_line_serials');
    }
};
