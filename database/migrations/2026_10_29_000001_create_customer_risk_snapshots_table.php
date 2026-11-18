<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_risk_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->decimal('score', 5, 2)->nullable();
            $table->string('grade', 3)->index();
            $table->string('risk_level', 20);
            $table->string('classification', 20)->index();
            $table->boolean('improving')->default(false);
            $table->string('status', 20)->index();
            $table->decimal('outstanding_amount', 14, 2)->default(0);
            $table->decimal('overdue_amount', 14, 2)->default(0);
            $table->unsignedInteger('overdue_count')->default(0);
            $table->decimal('avg_days_late', 8, 1)->default(0);
            $table->unsignedInteger('max_days_late')->default(0);
            $table->unsignedInteger('late_6m')->default(0);
            $table->unsignedInteger('current_streak')->default(0);
            $table->boolean('has_guarantor')->default(false);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_risk_snapshots');
    }
};
