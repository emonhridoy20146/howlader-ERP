<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('source_approved_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->timestamp('source_approved_at')->nullable()->after('approved_at');
            $table->foreignId('destination_approved_by')->nullable()->after('source_approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('destination_approved_at')->nullable()->after('destination_approved_by');
        });

        DB::table('stock_transfers')
            ->whereNotNull('approved_by')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('stock_transfers')
                        ->where('id', $row->id)
                        ->update([
                            'source_approved_by' => $row->approved_by,
                            'source_approved_at' => $row->approved_at,
                            'destination_approved_by' => $row->approved_by,
                            'destination_approved_at' => $row->approved_at,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropForeign(['source_approved_by']);
            $table->dropForeign(['destination_approved_by']);
            $table->dropColumn([
                'source_approved_by',
                'source_approved_at',
                'destination_approved_by',
                'destination_approved_at',
            ]);
        });
    }
};
