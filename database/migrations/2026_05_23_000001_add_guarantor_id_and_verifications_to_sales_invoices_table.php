<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->foreignId('guarantor_id')->nullable()->after('customer_id')->constrained('customers')->nullOnDelete();
            $table->string('customer_nid_path', 255)->nullable()->after('pdf_path');
            $table->string('guarantor_nid_path', 255)->nullable()->after('customer_nid_path');
            $table->json('verification_attachments')->nullable()->after('guarantor_nid_path');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropForeign(['guarantor_id']);
            $table->dropColumn(['guarantor_id', 'customer_nid_path', 'guarantor_nid_path', 'verification_attachments']);
        });
    }
};
