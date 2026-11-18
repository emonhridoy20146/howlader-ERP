<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wallets', 'location_id')) {
            return;
        }

        if (! Schema::hasTable('wallet_location')) {
            Schema::create('wallet_location', function (Blueprint $table) {
                $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
                $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
                $table->primary(['wallet_id', 'location_id']);
            });
        }

        if (DB::table('wallet_location')->count() === 0) {
            foreach (DB::table('wallets')->orderBy('id')->get() as $w) {
                DB::table('wallet_location')->insert([
                    'wallet_id' => $w->id,
                    'location_id' => $w->location_id,
                ]);
            }
        }

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique(['location_id', 'code']);
        });

        $duplicateCodes = DB::table('wallets')
            ->select('code')
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('code');

        foreach ($duplicateCodes as $code) {
            $rows = DB::table('wallets')->where('code', $code)->orderBy('id')->get();
            foreach ($rows->skip(1) as $row) {
                $newCode = substr($code.'-L'.$row->location_id, 0, 30);
                DB::table('wallets')->where('id', $row->id)->update(['code' => $newCode]);
                $newLedgerCode = 'W-'.$row->location_id.'-'.$newCode;
                if (strlen($newLedgerCode) > 40) {
                    $newLedgerCode = substr($newLedgerCode, 0, 40);
                }
                DB::table('ledger_accounts')->where('id', $row->ledger_account_id)->update(['code' => $newLedgerCode]);
            }
        }

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        // Irreversible: restoring single location_id and composite unique codes is lossy after multi-branch use.
    }
};
