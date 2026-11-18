<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recover from a prior failed run that created the table but not the foreign keys.
        Schema::dropIfExists('installment_writeoff_allocations');

        Schema::create('installment_writeoff_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_schedule_id')
                ->constrained('installment_schedules', indexName: 'iwo_alloc_installment_schedule_fk')
                ->cascadeOnDelete();
            $table->foreignId('invoice_debt_writeoff_id')
                ->constrained('invoice_debt_writeoffs', indexName: 'iwo_alloc_invoice_writeoff_fk')
                ->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_writeoff_allocations');
    }
};
