<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dizicashier_imported_stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('dizicashier_uuid')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dizicashier_imported_stock_transfers');
    }
};
