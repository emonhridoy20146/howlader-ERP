<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial_items', function (Blueprint $table) {
            if (! Schema::hasColumn('serial_items', 'manufacturer_serial')) {
                $table->string('manufacturer_serial', 100)->nullable()->after('serial_number');
            }
            if (! Schema::hasColumn('serial_items', 'serial_source')) {
                $table->enum('serial_source', ['manufacturer', 'internal'])->default('manufacturer')->after('manufacturer_serial');
            }
        });

        DB::table('serial_items')
            ->whereNull('serial_source')
            ->update(['serial_source' => 'manufacturer']);

        Schema::table('serial_items', function (Blueprint $table) {
            $table->index('manufacturer_serial');
            $table->index('serial_source');
        });
    }

    public function down(): void
    {
        Schema::table('serial_items', function (Blueprint $table) {
            $table->dropIndex(['manufacturer_serial']);
            $table->dropIndex(['serial_source']);
            if (Schema::hasColumn('serial_items', 'serial_source')) {
                $table->dropColumn('serial_source');
            }
            if (Schema::hasColumn('serial_items', 'manufacturer_serial')) {
                $table->dropColumn('manufacturer_serial');
            }
        });
    }
};
