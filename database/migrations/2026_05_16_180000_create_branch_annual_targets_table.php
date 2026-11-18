<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_annual_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('sales_target', 14, 2)->default(0);
            $table->decimal('collection_target', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['location_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_annual_targets');
    }
};
