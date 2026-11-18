<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->enum('type', ['cash', 'bank', 'card_terminal', 'mobile_banking']);

            // Common account/holder fields
            $table->string('account_name', 150)->nullable();
            $table->string('account_number', 80)->nullable();

            // Bank details
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_branch', 120)->nullable();
            $table->string('routing_number', 60)->nullable();
            $table->string('swift_code', 40)->nullable();

            // Card terminal details
            $table->string('terminal_provider', 120)->nullable();
            $table->string('terminal_id', 80)->nullable();
            $table->string('merchant_id', 80)->nullable();

            // Mobile banking details
            $table->string('mobile_provider', 120)->nullable();
            $table->string('mobile_number', 30)->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['location_id', 'is_active']);
            $table->index(['type', 'is_active']);
            $table->unique(['location_id', 'name']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('payment_method_id')->nullable()->after('payment_method')->constrained('payment_methods')->nullOnDelete();
            $table->index(['payment_method_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['payment_method_id', 'payment_date']);
            $table->dropConstrainedForeignId('payment_method_id');
        });

        Schema::dropIfExists('payment_methods');
    }
};
