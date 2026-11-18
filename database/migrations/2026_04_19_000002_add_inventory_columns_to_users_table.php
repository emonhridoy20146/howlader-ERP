<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('full_name', 100)->nullable()->after('id');
            $table->string('phone', 20)->nullable()->after('email');
            $table->enum('role', ['admin', 'branch_manager', 'sales', 'warehouse', 'accountant'])
                ->default('sales')
                ->after('password');
            $table->foreignId('branch_id')->nullable()->after('role')->constrained('locations')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('branch_id');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
        });

        // Keep compatibility with default Laravel name field while introducing full_name.
        DB::table('users')->whereNull('full_name')->update(['full_name' => DB::raw('name')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn([
                'full_name',
                'phone',
                'role',
                'branch_id',
                'is_active',
                'last_login_at',
            ]);
        });
    }
};
