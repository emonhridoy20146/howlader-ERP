<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_number', 30)->unique();
            $table->foreignId('serial_item_id')->constrained('serial_items');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('branch_id')->constrained('locations');
            $table->enum('type', ['warranty_repair', 'paid_repair', 'inspection']);
            $table->enum('status', ['intake', 'diagnosing', 'awaiting_parts', 'repairing', 'done', 'returned_to_customer', 'unrepairable']);
            $table->text('complaint');
            $table->text('diagnosis')->nullable();
            $table->text('work_done')->nullable();
            $table->decimal('repair_cost', 12, 2)->default(0);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('intake_date');
            $table->date('promised_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_jobs');
    }
};
