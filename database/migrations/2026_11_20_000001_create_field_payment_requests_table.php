<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive table for the field integration API: idempotency record of payments posted
 * from the field app. No existing table is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 64)->unique();
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('payment_id')->index();
            $table->unsignedBigInteger('sales_invoice_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->string('field_collection_id', 64)->nullable();
            $table->string('field_receipt_no', 40)->nullable();
            $table->json('response');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_payment_requests');
    }
};
