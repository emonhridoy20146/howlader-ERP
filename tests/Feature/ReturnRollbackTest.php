<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\ReturnOrder;
use App\Models\SaleLineSerial;
use App\Models\SerialItem;
use App\Support\LedgerAccountCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsReturnScenario;
use Tests\TestCase;

class ReturnRollbackTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReturnScenario;

    public function test_it_rolls_back_resolved_return_and_restores_wallet_and_sold_serial(): void
    {
        $this->seedCoreLedgerAccounts();

        [$user, $returnOrder, $wallet, $serial] = $this->seedReturnScenario(200000);

        $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'full',
            'refund_percentage' => 100,
            'pay_now_amount' => 200000,
            'wallet_id' => $wallet->id,
        ])->assertRedirect();

        $returnOrder->refresh();
        $serial->refresh();
        $this->assertSame('refunded', $returnOrder->status);
        $this->assertSame('in_stock', $serial->status);
        $this->assertNotNull(SaleLineSerial::query()->where('serial_item_id', $serial->id)->value('released_at'));

        $payment = Payment::query()->where('direction', 'outbound')->first();
        $this->assertNotNull($payment);
        $this->assertSame(1, JournalEntry::query()->where('source', 'refund_payment')->where('reference_id', $payment->id)->count());
        $this->assertSame(1, JournalEntry::query()->where('source', 'return_settlement')->where('reference_id', $returnOrder->id)->count());

        $response = $this->actingAs($user)->post(route('returns.rollback', $returnOrder), [
            'rollback_notes' => 'Entered by mistake',
        ]);

        $response->assertRedirect(route('returns.show', $returnOrder));

        $returnOrder->refresh();
        $serial->refresh();

        $this->assertNotNull($returnOrder->rolled_back_at);
        $this->assertSame('Entered by mistake', $returnOrder->rollback_notes);
        $this->assertSame('sold', $serial->status);
        $this->assertNull(SaleLineSerial::query()->where('serial_item_id', $serial->id)->value('released_at'));

        $this->assertDatabaseHas('stock_ledger', [
            'reference_type' => ReturnOrder::class,
            'reference_id' => $returnOrder->id,
            'movement_type' => 'sale_issue',
            'quantity_delta' => -1,
        ]);

        $this->assertSame(1, JournalEntry::query()->where('source', 'refund_payment_reversal')->count());
        $this->assertSame(1, JournalEntry::query()->where('source', 'return_settlement_reversal')->where('reference_id', $returnOrder->id)->count());
        $this->assertSame(0.0, $this->walletLedgerBalance((int) $wallet->ledger_account_id));
    }

    public function test_non_approver_cannot_rollback_return(): void
    {
        [$user, $returnOrder, $wallet] = $this->seedReturnScenario(200000);

        $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'full',
            'refund_percentage' => 100,
            'pay_now_amount' => 200000,
            'wallet_id' => $wallet->id,
        ]);

        $staff = \App\Models\User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $returnOrder->branch_id,
        ]);

        $this->actingAs($staff)->post(route('returns.rollback', $returnOrder))->assertForbidden();
    }

    private function walletLedgerBalance(int $ledgerAccountId): float
    {
        return (float) JournalLine::query()
            ->where('ledger_account_id', $ledgerAccountId)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
            ->value('balance');
    }

    private function seedCoreLedgerAccounts(): void
    {
        foreach ([
            [LedgerAccountCodes::ACCOUNTS_RECEIVABLE, 'Accounts Receivable', 'asset'],
            [LedgerAccountCodes::SALES_REVENUE, 'Sales Revenue', 'revenue'],
        ] as [$code, $name, $type]) {
            LedgerAccount::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'is_system' => true, 'is_active' => true],
            );
        }
    }
}
