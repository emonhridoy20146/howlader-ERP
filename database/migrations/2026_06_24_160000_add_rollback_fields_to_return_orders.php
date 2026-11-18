<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_orders', function (Blueprint $table): void {
            $table->timestamp('rolled_back_at')->nullable()->after('restocked_at');
            $table->foreignId('rolled_back_by')->nullable()->after('rolled_back_at')->constrained('users')->nullOnDelete();
            $table->text('rollback_notes')->nullable()->after('rolled_back_by');
        });
    }

    public function down(): void
    {
        Schema::table('return_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rolled_back_by');
            $table->dropColumn(['rolled_back_at', 'rollback_notes']);
        });
    }
};
