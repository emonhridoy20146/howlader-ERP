<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Console/sync jobs (e.g. Dizicashier import) have no acting user, so the collector may be unknown. */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('recorded_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Left nullable: re-tightening would fail on rows recorded without a user.
    }
};
