<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->unique()->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('kind', 30)->default('cash');
            $table->string('bank_name', 120)->nullable();
            $table->string('account_number', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
