<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->json('phone_numbers')->nullable()->after('phone');
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('guarantor_name', 150)->nullable()->after('installment_penalty_rate');
            $table->text('guarantor_address')->nullable()->after('guarantor_name');
            $table->json('guarantor_phone_numbers')->nullable()->after('guarantor_address');
            $table->text('guarantor_notes')->nullable()->after('guarantor_phone_numbers');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'guarantor_name',
                'guarantor_address',
                'guarantor_phone_numbers',
                'guarantor_notes',
            ]);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['phone_numbers']);
        });
    }
};
