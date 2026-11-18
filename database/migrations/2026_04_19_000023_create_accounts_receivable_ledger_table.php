<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts_receivable_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->timestamp('event_time')->useCurrent();
            $table->enum('event_type', ['invoice_posted', 'payment_allocated', 'credit_note', 'late_fee', 'writeoff']);
            $table->decimal('amount_delta', 12, 2);
            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id');
            $table->foreignId('actor_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['customer_id', 'event_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_receivable_ledger');
    }
};
