<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('subtotal_amount', 12, 2)->default(0)->after('notes');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('subtotal_amount');
            $table->decimal('tax_amount', 12, 2)->default(0)->after('discount_amount');
        });

        DB::table('purchase_orders')->update([
            'subtotal_amount' => DB::raw('total_amount'),
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]);

        Schema::create('purchase_order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_at');
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_payments');
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['subtotal_amount', 'discount_amount', 'tax_amount']);
        });
    }
};
