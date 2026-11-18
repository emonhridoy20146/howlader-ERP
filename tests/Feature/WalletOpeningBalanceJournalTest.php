<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletOpeningBalanceJournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_opening_balance_posts_debit_to_wallet_and_credit_to_equity(): void
    {
        $location = Location::create([
            'code' => 'LOC-OB',
            'name' => 'Opening branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $ledger = LedgerAccount::create([
            'code' => 'W-OB-TEST',
            'name' => 'OB test wallet',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = Wallet::create([
            'ledger_account_id' => $ledger->id,
            'code' => 'OB-TEST',
            'name' => 'OB test wallet',
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$location->id]);

        $response = $this->actingAs($user)->post(route('journal-entries.store'), [
            'entry_kind' => 'wallet_opening',
            'branch_id' => $location->id,
            'entry_date' => '2026-04-01',
            'wallet_id' => $wallet->id,
            'amount' => 15000.5,
            'memo' => 'Initial float',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('journal_entries', [
            'branch_id' => $location->id,
            'source' => 'wallet_opening_balance',
        ]);

        $entryId = (int) JournalEntry::query()->where('source', 'wallet_opening_balance')->value('id');
        $this->assertGreaterThan(0, $entryId);

        $equityId = LedgerAccount::query()->where('code', '3900-OPENING-EQUITY')->value('id');
        $this->assertNotNull($equityId);

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entryId,
            'ledger_account_id' => $ledger->id,
            'debit' => 15000.5,
            'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entryId,
            'ledger_account_id' => $equityId,
            'debit' => 0,
            'credit' => 15000.5,
        ]);

        $wallet->refresh();
        $this->assertEquals(15000.5, $wallet->currentBalance());
    }
}
