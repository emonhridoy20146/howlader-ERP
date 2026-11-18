<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->timestamp('event_time')->useCurrent();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('serial_item_id')->nullable()->constrained('serial_items')->nullOnDelete();
            $table->foreignId('location_id')->constrained('locations');
            $table->enum('movement_type', [
                'purchase_receive', 'sale_issue', 'return_receive', 'adjust_in', 'adjust_out',
                'transfer_issue', 'transfer_receive', 'damage_out', 'reserve', 'unreserve',
            ]);
            $table->integer('quantity_delta');
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id');
            $table->foreignId('actor_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['location_id', 'product_id', 'event_time']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledger');
    }
};
