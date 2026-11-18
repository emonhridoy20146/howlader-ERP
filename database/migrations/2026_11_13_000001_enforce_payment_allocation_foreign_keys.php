<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Some databases were built without the payment_allocations foreign keys that the
 * original migration declares, so deleting a payment left orphan allocation rows.
 * Adds the constraints where they are missing. Refuses to run while orphans exist:
 * review them with `php artisan invoices:check-integrity` and clean up first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('payment_allocations as a')
            ->leftJoin('payments as p', 'p.id', '=', 'a.payment_id')
            ->whereNull('p.id')
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "{$orphans} orphan payment_allocations rows exist (payment record missing). "
                .'Review with `php artisan invoices:check-integrity` and resolve them before adding the foreign key.'
            );
        }

        if (! $this->hasForeignKey('payment_allocations', 'payment_id')) {
            Schema::table('payment_allocations', function (Blueprint $table): void {
                $table->foreign('payment_id')->references('id')->on('payments')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->hasForeignKey('payment_allocations', 'payment_id')) {
            Schema::table('payment_allocations', function (Blueprint $table): void {
                $table->dropForeign(['payment_id']);
            });
        }
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))
            ->contains(fn (array $fk): bool => in_array($column, $fk['columns'], true));
    }
};
