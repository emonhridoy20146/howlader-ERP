<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('serial_item_id')->constrained('serial_items');
            $table->foreignId('product_id')->constrained('products');
            $table->enum('status', ['planned', 'issued', 'received', 'cancelled'])->default('planned');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'serial_item_id']);
            $table->index(['stock_transfer_id', 'status']);
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_serials');
    }
};
