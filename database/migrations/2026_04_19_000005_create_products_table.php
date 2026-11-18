<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 60)->unique();
            $table->string('barcode', 100)->nullable()->unique();
            $table->foreignId('brand_id')->constrained('brands');
            $table->foreignId('category_id')->constrained('categories');
            $table->string('name', 200);
            $table->string('model_number', 100)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('warranty_months')->default(12);
            $table->decimal('default_purchase_price', 12, 2)->default(0);
            $table->decimal('default_sale_price', 12, 2)->default(0);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->string('unit_of_measure', 20)->default('unit');
            $table->boolean('is_serialized')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
