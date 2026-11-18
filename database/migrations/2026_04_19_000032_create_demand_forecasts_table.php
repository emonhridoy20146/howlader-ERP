<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('location_id')->constrained('locations');
            $table->date('forecast_month');
            $table->decimal('predicted_qty', 10, 2);
            $table->decimal('actual_qty', 10, 2)->nullable();
            $table->string('algorithm', 40)->default('moving_average_3m');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['product_id', 'location_id', 'forecast_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_forecasts');
    }
};
