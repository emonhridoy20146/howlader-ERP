<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_orders', function (Blueprint $table): void {
            $table->foreignId('accepted_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable()->after('accepted_by');

            $table->decimal('refund_base_amount', 12, 2)->default(0)->after('accepted_at');
            $table->decimal('refund_percentage', 5, 2)->default(100)->after('refund_base_amount');
            $table->decimal('refund_settlement_amount', 12, 2)->default(0)->after('refund_percentage');
            $table->decimal('refund_paid_amount', 12, 2)->default(0)->after('refund_settlement_amount');
            $table->decimal('refund_due_amount', 12, 2)->default(0)->after('refund_paid_amount');
            $table->enum('refund_status', ['unpaid', 'partial', 'paid', 'waived'])->default('unpaid')->after('refund_due_amount');
            $table->text('resolution_notes')->nullable()->after('refund_status');
            $table->timestamp('restocked_at')->nullable()->after('resolution_notes');
        });
    }

    public function down(): void
    {
        Schema::table('return_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn([
                'accepted_at',
                'refund_base_amount',
                'refund_percentage',
                'refund_settlement_amount',
                'refund_paid_amount',
                'refund_due_amount',
                'refund_status',
                'resolution_notes',
                'restocked_at',
            ]);
        });
    }
};
