<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'location_id')) {
            return;
        }

        if (! Schema::hasTable('employee_location')) {
            Schema::create('employee_location', function (Blueprint $table) {
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
                $table->primary(['employee_id', 'location_id']);
            });
        }

        if (DB::table('employee_location')->count() === 0) {
            foreach (DB::table('employees')->whereNotNull('location_id')->orderBy('id')->get() as $employee) {
                DB::table('employee_location')->insert([
                    'employee_id' => $employee->id,
                    'location_id' => $employee->location_id,
                ]);
            }
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }

    public function down(): void
    {
        // Irreversible: restoring a single location_id is lossy after multi-branch use.
    }
};
