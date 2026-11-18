<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_schedule_id')->constrained('installment_schedules')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments');
            $table->decimal('allocated_amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_payment_allocations');
    }
};
