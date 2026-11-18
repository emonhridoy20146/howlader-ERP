<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ledger_accounts')) {
            $now = now();
            DB::table('ledger_accounts')->insertOrIgnore([
                [
                    'code' => '6200-BAD-DEBT',
                    'name' => 'Bad debt expense',
                    'type' => 'expense',
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }

        Schema::create('invoice_debt_writeoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->text('reason')->nullable();
            $table->foreignId('actor_user_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_debt_writeoffs');

        if (Schema::hasTable('ledger_accounts')) {
            DB::table('ledger_accounts')->where('code', '6200-BAD-DEBT')->delete();
        }
    }
};
