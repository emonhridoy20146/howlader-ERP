<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('location_id')->constrained('locations');
            $table->integer('qty_before')->default(0);
            $table->integer('qty_after')->default(0);
            $table->integer('qty_delta');
            $table->text('reason')->nullable();
            $table->foreignId('actor_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['product_id', 'location_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_stock_adjustments');
    }
};
