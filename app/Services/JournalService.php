<?php

namespace App\Services;

use App\Models\InvoiceDebtWriteoff;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethodWalletMap;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCostPosting;
use App\Models\PurchaseOrderPayment;
use App\Models\ReturnOrder;
use App\Models\SalesInvoice;
use App\Models\Wallet;
use App\Support\LedgerAccountCodes;
use App\Support\OwnerCapitalWallet;
use App\Exceptions\InvalidStateException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class JournalService
{
    public function ledgerAccountByCode(string $code): ?LedgerAccount
    {
        return LedgerAccount::query()->where('code', $code)->first();
    }

    public function resolveWalletForPayment(int $branchId, ?int $paymentMethodId): ?Wallet
    {
        if (! $paymentMethodId) {
            return null;
        }

        $map = PaymentMethodWalletMap::query()
            ->where('location_id', $branchId)
            ->where('payment_method_id', $paymentMethodId)
            ->first();

        if (! $map) {
            return null;
        }

        return Wallet::query()
            ->whereKey($map->wallet_id)
            ->whereAppliesToLocation($branchId)
            ->where('is_active', true)
            ->whereHas('ledgerAccount', fn ($q) => $q->where('is_active', true))
            ->first();
    }

    public function postSalesInvoiceGl(SalesInvoice $invoice, int $actorUserId): void
    {
        $amount = round((float) $invoice->grand_total, 2);
        if ($amount <= 0) {
            return;
        }

        if ($this->entryExists('sales_invoice', SalesInvoice::class, $invoice->id)) {
            return;
        }

        $ar = $this->requireAccount(LedgerAccountCodes::ACCOUNTS_RECEIVABLE);
        $revenue = $this->requireAccount(LedgerAccountCodes::SALES_REVENUE);

        $this->postBalanced(
            branchId: (int) $invoice->branch_id,
            entryDate: optional($invoice->invoice_date)?->toDateString()
                ?? optional($invoice->posted_at)?->toDateString()
                ?? now()->toDateString(),
            description: 'Invoice posted — '.($invoice->invoice_number ?? '#'.$invoice->id),
            source: 'sales_invoice',
            lines: [
                ['ledger_account_id' => $ar->id, 'debit' => $amount, 'credit' => 0, 'line_memo' => 'AR'],
                ['ledger_account_id' => $revenue->id, 'debit' => 0, 'credit' => $amount, 'line_memo' => 'Revenue'],
            ],
            actorUserId: $actorUserId,
            referenceType: SalesInvoice::class,
            referenceId: $invoice->id,
        );
    }

    /**
     * Replace the GL posting for a sales invoice (used after invoice totals/lines change).
     */
    public function refreshSalesInvoiceGl(SalesInvoice $invoice, int $actorUserId): void
    {
        $entryIds = JournalEntry::query()
            ->where('source', 'sales_invoice')
            ->where('reference_id', $invoice->id)
            ->where(function ($q): void {
                $q->where('reference_type', SalesInvoice::class)
                    ->orWhere('reference_type', 'SalesInvoice');
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($entryIds !== []) {
            JournalLine::query()->whereIn('journal_entry_id', $entryIds)->delete();
            JournalEntry::query()->whereIn('id', $entryIds)->delete();
        }

        $this->postSalesInvoiceGl($invoice->fresh(), $actorUserId);
    }

    public function postCustomerPaymentGl(
        int $branchId,
        float $amount,
        int $paymentMethodId,
        Payment $payment,
        int $actorUserId,
        bool $requireWallet = false,
    ): bool {
        return $this->postCustomerPaymentToWalletGl(
            $branchId,
            $amount,
            $paymentMethodId,
            $payment,
            $actorUserId,
            creditLedgerAccount: $this->requireAccount(LedgerAccountCodes::ACCOUNTS_RECEIVABLE),
            creditLineMemo: 'AR',
            description: InstallmentAdvanceService::isAdvanceReference($payment->reference_no)
                ? 'Installment advance '.$payment->payment_number
                : 'Customer payment '.$payment->payment_number,
            requireWallet: $requireWallet,
        ) instanceof Wallet;
    }

    /**
     * Historical backfill with wallet update: debit wallet, credit clearing (invoice AR totals unchanged).
     */
    public function postHistoricalPaymentToWalletGl(
        int $branchId,
        float $amount,
        int $paymentMethodId,
        Payment $payment,
        int $actorUserId,
    ): ?Wallet {
        $clearing = $this->requireHistoricalPaymentClearingAccount();
        $description = InstallmentAdvanceService::isAdvanceReference($payment->reference_no)
            ? 'Historical installment advance '.$payment->payment_number
            : 'Historical customer payment '.$payment->payment_number;

        return $this->postCustomerPaymentToWalletGl(
            $branchId,
            $amount,
            $paymentMethodId,
            $payment,
            $actorUserId,
            creditLedgerAccount: $clearing,
            creditLineMemo: 'Historical payment clearing',
            description: $description,
            requireWallet: true,
        );
    }

    private function postCustomerPaymentToWalletGl(
        int $branchId,
        float $amount,
        int $paymentMethodId,
        Payment $payment,
        int $actorUserId,
        LedgerAccount $creditLedgerAccount,
        string $creditLineMemo,
        string $description,
        bool $requireWallet = false,
    ): ?Wallet {
        $amt = round($amount, 2);
        if ($amt <= 0) {
            return null;
        }

        if ($this->entryExists('sales_payment', Payment::class, $payment->id)) {
            return null;
        }

        $wallet = $this->resolveWalletForPayment($branchId, $paymentMethodId);
        if (! $wallet) {
            if ($requireWallet) {
                throw new InvalidStateException('No wallet is mapped for this payment method at this branch.');
            }

            Log::warning('GL customer payment skipped: no wallet mapped for payment method.', [
                'branch_id' => $branchId,
                'payment_method_id' => $paymentMethodId,
                'payment_id' => $payment->id,
            ]);

            return null;
        }

        $this->postBalanced(
            branchId: $branchId,
            entryDate: $payment->payment_date->toDateString(),
            description: $description,
            source: 'sales_payment',
            lines: [
                ['ledger_account_id' => $wallet->ledger_account_id, 'debit' => $amt, 'credit' => 0, 'line_memo' => $wallet->name],
                ['ledger_account_id' => $creditLedgerAccount->id, 'debit' => 0, 'credit' => $amt, 'line_memo' => $creditLineMemo],
            ],
            actorUserId: $actorUserId,
            referenceType: Payment::class,
            referenceId: $payment->id,
        );

        return $wallet;
    }

    public function salesPaymentJournalEntry(Payment $payment): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_type', Payment::class)
            ->where('reference_id', $payment->id)
            ->with('lines')
            ->first();
    }

    public function deleteSalesPaymentJournal(Payment $payment): bool
    {
        $entryIds = JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_type', Payment::class)
            ->where('reference_id', $payment->id)
            ->pluck('id');

        if ($entryIds->isEmpty()) {
            return false;
        }

        JournalLine::query()->whereIn('journal_entry_id', $entryIds)->delete();
        JournalEntry::query()->whereIn('id', $entryIds)->delete();

        return true;
    }

    /**
     * Record a customer payment in GL without debiting a wallet ledger (historical / backfill).
     */
    public function postHistoricalCustomerPaymentGl(
        int $branchId,
        float $amount,
        Payment $payment,
        int $actorUserId,
    ): bool {
        $amt = round($amount, 2);
        if ($amt <= 0) {
            return false;
        }

        if ($this->entryExists('sales_payment', Payment::class, $payment->id)) {
            return false;
        }

        $clearing = $this->requireHistoricalPaymentClearingAccount();
        $ar = $this->requireAccount(LedgerAccountCodes::ACCOUNTS_RECEIVABLE);
        $description = InstallmentAdvanceService::isAdvanceReference($payment->reference_no)
            ? 'Historical installment advance '.$payment->payment_number
            : 'Historical customer payment '.$payment->payment_number;

        $this->postBalanced(
            branchId: $branchId,
            entryDate: $payment->payment_date->toDateString(),
            description: $description,
            source: 'sales_payment',
            lines: [
                ['ledger_account_id' => $clearing->id, 'debit' => $amt, 'credit' => 0, 'line_memo' => 'Historical payment (wallet not updated)'],
                ['ledger_account_id' => $ar->id, 'debit' => 0, 'credit' => $amt, 'line_memo' => 'AR'],
            ],
            actorUserId: $actorUserId,
            referenceType: Payment::class,
            referenceId: $payment->id,
        );

        return true;
    }

    /**
     * Sales return settlement: reduce revenue and credit AR (credit note) before cash refund.
     */
    public function postReturnSettlementGl(
        ReturnOrder $returnOrder,
        SalesInvoice $invoice,
        float $amount,
        int $actorUserId,
    ): void {
        $amt = round($amount, 2);
        if ($amt <= 0) {
            return;
        }

        if ($this->entryExists('return_settlement', ReturnOrder::class, $returnOrder->id)) {
            return;
        }

        $revenue = $this->requireAccount(LedgerAccountCodes::SALES_REVENUE);
        $ar = $this->requireAccount(LedgerAccountCodes::ACCOUNTS_RECEIVABLE);

        $this->postBalanced(
            branchId: (int) $returnOrder->branch_id,
            entryDate: $returnOrder->return_date?->toDateString() ?? now()->toDateString(),
            description: 'Return settlement — '.$returnOrder->return_number.' (Invoice '.($invoice->invoice_number ?? '#'.$invoice->id).')',
            source: 'return_settlement',
            lines: [
                ['ledger_account_id' => $revenue->id, 'debit' => $amt, 'credit' => 0, 'line_memo' => 'Sales return'],
                ['ledger_account_id' => $ar->id, 'debit' => 0, 'credit' => $amt, 'line_memo' => 'AR credit'],
            ],
            actorUserId: $actorUserId,
            referenceType: ReturnOrder::class,
            referenceId: $returnOrder->id,
        );
    }

    /**
     * Undo return settlement GL on rollback.
     */
    public function postReturnSettlementReversalGl(
        ReturnOrder $returnOrder,
        SalesInvoice $invoice,
        float $amount,
        int $actorUserId,
    ): void {
        $amt = round($amount, 2);
        if ($amt <= 0) {
            return;
        }

        if (! $this->entryExists('return_settlement', ReturnOrder::class, $returnOrder->id)) {
            return;
        }

        if ($this->entryExists('return_settlement_reversal', ReturnOrder::class, $returnOrder->id)) {
            return;
        }

        $revenue = $this->requireAccount(LedgerAccountCodes::SALES_REVENUE);
        $ar = $this->requireAccount(LedgerAccountCodes::ACCOUNTS_RECEIVABLE);

        $this->postBalanced(
            branchId: (int) $returnOrder->branch_id,
            entryDate: now()->toDateString(),
            description: 'Rollback return settlement — '.$returnOrder->return_number.' (Invoice '.($invoice->invoice_number ?? '#'.$invoice->id).')',
            source: 'return_settlement_reversal',
            lines: [
                ['ledger_account_id' => $ar->id, 'debit' => $amt, 'credit' => 0, 'line_memo' => 'AR'],
                ['ledger_account_id' => $revenue->id, 'debit' => 0, 'credit' => $amt, 'line_memo' => 'Sales return reversal'],
            ],
            actorUserId: $actorUserId,
            referenceType: ReturnOrder::class,
            referenceId: $returnOrder->id,
        );
    }

    public function postRefundPaymentGl(
        int $branchId,
        float $amount,
        int $walletId,
        Payment $payment,
        int $actorUserId,
    ): void {
        $amt = round($amount, 2);
        if ($amt <= 0) {
            return;
        }

        if ($this->entryExists('refund_payment', Payment::class, $payment->id)) {
            return;
        }

        $wallet = \App\Models\Wallet::query()->findOrFail($walletId);
        $ar = $this->requireAccount(LedgerAccountCodes::ACCOUNTS_RECEIVABLE);

        $this->postBalanced(
            branchId: $branchId,
            entryDate: $payment->payment_date->toDateString(),
            description: 'Refund payment '.$payment->payment_number,
            source: 'refund_payment',
            lines: [
                ['ledger_account_id' => $ar->id, 'debit' => $amt, 'credit' => 0, 'line_memo' => 'AR'],
                ['ledger_account_id' => $wallet->ledger_account_id, 'debit' => 0, 'credit' => $amt, 'line_memo' => $wallet->name],
            ],
            actorUserId: $actorUserId,
            referenceType: Payment::class,
            referenceId: $payment->id,
        );
    }

    /**
     * Undo an outbound refund payment: debit wallet (restore balance), credit AR.
     */
    public function postRefundPaymentReversalGl(
        int $branchId,
        float $amount,
        int $walletId,
        Payment $payment,
        int $actorUserId,
        ReturnOrder $returnOrder,
    ): void {
        $amt = round($amount, 2);
        if ($amt <= 0) {
            return;
        }

        if ($this->entryExists('refund_payment_reversal', Payment::class, $payment->id)) {
            return;
        }

        $wallet = Wallet::query()->findOrFail($walletId);
        $ar = $this->requireAccount(LedgerAccountCodes::ACCOUNTS_RECEIVABLE);

        $this->postBalanced(
            branchId: $branchId,
            entryDate: $payment->payment_date->toDateString(),
            description: 'Rollback refund '.$payment->payment_number.' ('.$returnOrder->return_number.')',
            source: 'refund_payment_reversal',
            lines: [
                ['ledger_account_id' => $wallet->ledger_account_id, 'debit' => $amt, 'credit' => 0, 'line_memo' => $wallet->name],
                ['ledger_account_id' => $ar->id, 'debit' => 0, 'credit' => $amt, 'line_memo' => 'AR'],
            ],
            actorUserId: $actorUserId,
            referenceType: Payment::class,
            referenceId: $payment->id,
        );
    }

    /**
     * Settlement discount taken at payment time: debit expense, credit AR (no cash movement).
     */
    public function postPaymentSettlementDiscountGl(
        SalesInvoice $invoice,
        PaymentAllocation $allocation,
        float $amount,
        int $actorUserId,
        ?string $entryDate = null,
    ): void {
        $amt = round($amount, 2);
        if ($amt <= 0) {
            return;
        }

        if ($this->entryExists('sales_payment_discount', PaymentAllocation::class, $allocation->id)) {
            return;
        }

        $discountExpense = $this->requireAccount(LedgerAccountCodes::PAYMENT_SETTLEMENT_DISCOUNT);
        $ar = $this->requireAccount(LedgerAccountCodes::ACCOUNTS_RECEIVABLE);
        $memo = 'Payment settlement discount — '.($invoice->invoice_number ?? '#'.$invoice->id);
        $memo = Str::limit($memo, 450, '…');

        $this->postBalanced(
            branchId: (int) $invoice->branch_id,
            entryDate: $entryDate ?? now()->toDateString(),
            description: $memo,
            source: 'sales_payment_discount',
            lines: [
                ['ledger_account_id' => $discountExpense->id, 'debit' => $amt, 'credit' => 0, 'line_memo' => 'Settlement discount'],
                ['ledger_account_id' => $ar->id, 'debit' => 0, 'credit' => $amt, 'line_memo' => 'AR'],
            ],
            actorUserId: $actorUserId,
            referenceType: PaymentAllocation::class,
            referenceId: $allocation->id,
        );
    }

    /**
     * Forgive uncollectible AR: debit bad debt expense, credit AR.
     */
    public function postInvoiceDebtWriteoffGl(
        InvoiceDebtWriteoff $writeoff,
        SalesInvoice $invoice,
        int $actorUserId,
    ): void {
        $amt = round((float) $writeoff->amount, 2);
        if ($amt <= 0) {
            return;
        }

        if ($this->entryExists('debt_writeoff', InvoiceDebtWriteoff::class, $writeoff->id)) {
            return;
        }

        $badDebt = $this->requireAccount(LedgerAccountCodes::BAD_DEBT_EXPENSE);
        $ar = $this->requireAccount(LedgerAccountCodes::ACCOUNTS_RECEIVABLE);
        $memo = trim((string) $writeoff->reason) !== ''
            ? 'Bad debt write-off — '.$invoice->invoice_number.' — '.$writeoff->reason
            : 'Bad debt write-off — '.$invoice->invoice_number;
        $memo = Str::limit($memo, 450, '…');

        $this->postBalanced(
            branchId: (int) $invoice->branch_id,
            entryDate: now()->toDateString(),
            description: $memo,
            source: 'debt_writeoff',
            lines: [
                ['ledger_account_id' => $badDebt->id, 'debit' => $amt, 'credit' => 0, 'line_memo' => 'Bad debt'],
                ['ledger_account_id' => $ar->id, 'debit' => 0, 'credit' => $amt, 'line_memo' => 'AR'],
            ],
            actorUserId: $actorUserId,
            referenceType: InvoiceDebtWriteoff::class,
            referenceId: $writeoff->id,
        );
    }

    /**
     * Supplier PO payment: reduce accounts payable, credit (pay from) wallet cash/bank ledger.
     */
    public function postSupplierPoPayment(PurchaseOrder $purchaseOrder, PurchaseOrderPayment $payment, int $walletId, int $actorUserId): void
    {
        $cash = round((float) $payment->amount, 2);
        $discount = round((float) ($payment->settlement_discount ?? 0), 2);
        $apApplied = round($cash + $discount, 2);

        if ($apApplied <= 0) {
            return;
        }

        if ($this->entryExists('po_supplier_payment', PurchaseOrderPayment::class, $payment->id)) {
            return;
        }

        $branchId = (int) $purchaseOrder->destination_location_id;
        $wallet = Wallet::query()
            ->whereKey($walletId)
            ->where('is_active', true)
            ->whereAppliesToLocation($branchId)
            ->whereHas('ledgerAccount', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();

        $ap = $this->requireAccount(LedgerAccountCodes::ACCOUNTS_PAYABLE);

        $lines = [
            ['ledger_account_id' => $ap->id, 'debit' => $apApplied, 'credit' => 0, 'line_memo' => 'AP'],
        ];

        if ($cash > 0.009) {
            $lines[] = ['ledger_account_id' => $wallet->ledger_account_id, 'debit' => 0, 'credit' => $cash, 'line_memo' => $wallet->name];
        }

        if ($discount > 0.009) {
            $discountAccount = $this->requireAccount(LedgerAccountCodes::PAYMENT_SETTLEMENT_DISCOUNT);
            $lines[] = ['ledger_account_id' => $discountAccount->id, 'debit' => 0, 'credit' => $discount, 'line_memo' => 'Supplier settlement discount'];
        }

        $this->postBalanced(
            branchId: $branchId,
            entryDate: $payment->paid_at->toDateString(),
            description: 'PO '.($purchaseOrder->po_number ?? '#'.$purchaseOrder->id).' — supplier payment',
            source: 'po_supplier_payment',
            lines: $lines,
            actorUserId: $actorUserId,
            referenceType: PurchaseOrderPayment::class,
            referenceId: $payment->id,
        );
    }

    /**
     * Refund excess supplier PO payment into a wallet: debit wallet cash/bank, credit AP.
     * Payment.amount is stored negative; journal uses the absolute cash returned.
     */
    public function postSupplierPoRefund(PurchaseOrder $purchaseOrder, PurchaseOrderPayment $payment, int $walletId, int $actorUserId): void
    {
        $cash = abs(round((float) $payment->amount, 2));
        if ($cash <= 0.009) {
            return;
        }

        if ($this->entryExists('po_supplier_refund', PurchaseOrderPayment::class, $payment->id)) {
            return;
        }

        $branchId = (int) $purchaseOrder->destination_location_id;
        $wallet = Wallet::query()
            ->whereKey($walletId)
            ->where('is_active', true)
            ->whereAppliesToLocation($branchId)
            ->whereHas('ledgerAccount', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();

        $ap = $this->requireAccount(LedgerAccountCodes::ACCOUNTS_PAYABLE);

        $this->postBalanced(
            branchId: $branchId,
            entryDate: $payment->paid_at->toDateString(),
            description: 'PO '.($purchaseOrder->po_number ?? '#'.$purchaseOrder->id).' — supplier excess refund',
            source: 'po_supplier_refund',
            lines: [
                ['ledger_account_id' => $wallet->ledger_account_id, 'debit' => $cash, 'credit' => 0, 'line_memo' => $wallet->name],
                ['ledger_account_id' => $ap->id, 'debit' => 0, 'credit' => $cash, 'line_memo' => 'AP'],
            ],
            actorUserId: $actorUserId,
            referenceType: PurchaseOrderPayment::class,
            referenceId: $payment->id,
        );
    }

    /**
     * PO additional cost paid from company wallet (transport, unload, etc.).
     * Debits logistics expense, credits wallet cash/bank ledger.
     */
    public function postPurchaseOrderAdditionalCost(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderCostPosting $posting,
        ?int $actorUserId,
    ): void {
        $amt = round((float) $posting->amount, 2);
        if ($amt <= 0) {
            return;
        }

        if ($this->entryExists('po_additional_cost', PurchaseOrderCostPosting::class, $posting->id)) {
            return;
        }

        $branchId = (int) $purchaseOrder->destination_location_id;
        $wallet = Wallet::query()
            ->whereKey((int) $posting->wallet_id)
            ->where('is_active', true)
            ->whereAppliesToLocation($branchId)
            ->whereHas('ledgerAccount', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();

        $expense = $this->requireAccount(LedgerAccountCodes::PO_LOGISTICS_EXPENSE);

        $entry = $this->postBalanced(
            branchId: $branchId,
            entryDate: $posting->paid_at->toDateString(),
            description: 'PO '.($purchaseOrder->po_number ?? '#'.$purchaseOrder->id).' — '.$posting->label,
            source: 'po_additional_cost',
            lines: [
                ['ledger_account_id' => $expense->id, 'debit' => $amt, 'credit' => 0, 'line_memo' => $posting->label],
                ['ledger_account_id' => $wallet->ledger_account_id, 'debit' => 0, 'credit' => $amt, 'line_memo' => $wallet->name],
            ],
            actorUserId: $actorUserId,
            referenceType: PurchaseOrderCostPosting::class,
            referenceId: $posting->id,
        );

        $posting->forceFill(['journal_entry_id' => $entry->id])->save();
    }

    public function postManualExpense(int $branchId, string $entryDate, int $expenseLedgerId, int $walletId, float $amount, string $memo, int $actorUserId): JournalEntry
    {
        $wallet = Wallet::query()->whereKey($walletId)->whereAppliesToLocation($branchId)->firstOrFail();
        LedgerAccount::query()->whereKey($expenseLedgerId)->where('type', 'expense')->firstOrFail();
        $amt = round($amount, 2);

        return $this->postBalanced(
            branchId: $branchId,
            entryDate: $entryDate,
            description: $memo !== '' ? $memo : 'Operating expense',
            source: 'manual_expense',
            lines: [
                ['ledger_account_id' => $expenseLedgerId, 'debit' => $amt, 'credit' => 0, 'line_memo' => 'Expense'],
                ['ledger_account_id' => $wallet->ledger_account_id, 'debit' => 0, 'credit' => $amt, 'line_memo' => $wallet->name],
            ],
            actorUserId: $actorUserId,
            referenceType: null,
            referenceId: null,
            status: 'draft',
        );
    }

    /**
     * @param  list<array{ledger_account_id: int, amount: float, memo?: string|null}>  $expenses
     */
    public function postMultipleManualExpenses(int $branchId, string $entryDate, array $expenses, int $walletId, string $globalMemo, int $actorUserId): JournalEntry
    {
        $wallet = Wallet::query()->whereKey($walletId)->whereAppliesToLocation($branchId)->firstOrFail();

        $lines = [];
        $totalAmt = 0.0;

        foreach ($expenses as $exp) {
            $amt = round((float) $exp['amount'], 2);
            if ($amt <= 0) {
                continue;
            }

            $ledger = LedgerAccount::query()->whereKey($exp['ledger_account_id'])->where('type', 'expense')->firstOrFail();
            $memo = trim((string) ($exp['memo'] ?? ''));

            $lines[] = [
                'ledger_account_id' => $ledger->id,
                'debit' => $amt,
                'credit' => 0,
                'line_memo' => $memo !== '' ? $memo : 'Expense',
            ];

            $totalAmt += $amt;
        }

        if ($totalAmt <= 0) {
            throw new \InvalidArgumentException('Total expense amount must be greater than zero.');
        }

        $lines[] = [
            'ledger_account_id' => $wallet->ledger_account_id,
            'debit' => 0,
            'credit' => $totalAmt,
            'line_memo' => $wallet->name,
        ];

        return $this->postBalanced(
            branchId: $branchId,
            entryDate: $entryDate,
            description: $globalMemo !== '' ? $globalMemo : 'Operating expenses',
            source: 'manual_expense',
            lines: $lines,
            actorUserId: $actorUserId,
            referenceType: null,
            referenceId: null,
            status: 'draft',
        );
    }

    public function postManualIncome(int $branchId, string $entryDate, int $incomeLedgerId, int $walletId, float $amount, string $memo, int $actorUserId): JournalEntry
    {
        $wallet = Wallet::query()->whereKey($walletId)->whereAppliesToLocation($branchId)->firstOrFail();
        LedgerAccount::query()->whereKey($incomeLedgerId)->where('type', 'income')->firstOrFail();
        $amt = round($amount, 2);

        return $this->postBalanced(
            branchId: $branchId,
            entryDate: $entryDate,
            description: $memo !== '' ? $memo : 'Other income',
            source: 'manual_income',
            lines: [
                ['ledger_account_id' => $wallet->ledger_account_id, 'debit' => $amt, 'credit' => 0, 'line_memo' => $wallet->name],
                ['ledger_account_id' => $incomeLedgerId, 'debit' => 0, 'credit' => $amt, 'line_memo' => 'Income'],
            ],
            actorUserId: $actorUserId,
            referenceType: null,
            referenceId: null,
            status: 'draft',
        );
    }

    /**
     * @param  list<array{ledger_account_id: int, amount: float, memo?: string|null}>  $incomeLines
     */
    public function postMultipleManualIncomes(int $branchId, string $entryDate, array $incomeLines, int $walletId, string $globalMemo, int $actorUserId): JournalEntry
    {
        $wallet = Wallet::query()->whereKey($walletId)->whereAppliesToLocation($branchId)->firstOrFail();

        $lines = [];
        $totalAmt = 0.0;

        foreach ($incomeLines as $row) {
            $amt = round((float) $row['amount'], 2);
            if ($amt <= 0) {
                continue;
            }

            $ledger = LedgerAccount::query()->whereKey($row['ledger_account_id'])->where('type', 'income')->firstOrFail();
            $memo = trim((string) ($row['memo'] ?? ''));

            $lines[] = [
                'ledger_account_id' => $ledger->id,
                'debit' => 0,
                'credit' => $amt,
                'line_memo' => $memo !== '' ? $memo : 'Income',
            ];

            $totalAmt += $amt;
        }

        if ($totalAmt <= 0) {
            throw new \InvalidArgumentException('Total income amount must be greater than zero.');
        }

        $lines[] = [
            'ledger_account_id' => $wallet->ledger_account_id,
            'debit' => $totalAmt,
            'credit' => 0,
            'line_memo' => $wallet->name,
        ];

        return $this->postBalanced(
            branchId: $branchId,
            entryDate: $entryDate,
            description: $globalMemo !== '' ? $globalMemo : 'Other income',
            source: 'manual_income',
            lines: $lines,
            actorUserId: $actorUserId,
            referenceType: null,
            referenceId: null,
            status: 'draft',
        );
    }

    public function postWalletTransfer(int $branchId, string $entryDate, int $fromWalletId, int $toWalletId, float $amount, string $memo, int $actorUserId): JournalEntry
    {
        $from = Wallet::query()->whereKey($fromWalletId)->whereAppliesToLocation($branchId)->firstOrFail();
        $to = Wallet::query()->whereKey($toWalletId)->whereAppliesToLocation($branchId)->firstOrFail();
        if ($from->id === $to->id) {
            throw new \InvalidArgumentException('Source and destination wallets must differ.');
        }

        $amt = round($amount, 2);

        return $this->postBalanced(
            branchId: $branchId,
            entryDate: $entryDate,
            description: $memo !== '' ? $memo : 'Wallet transfer',
            source: 'manual_transfer',
            lines: [
                ['ledger_account_id' => $to->ledger_account_id, 'debit' => $amt, 'credit' => 0, 'line_memo' => 'To '.$to->name],
                ['ledger_account_id' => $from->ledger_account_id, 'debit' => 0, 'credit' => $amt, 'line_memo' => 'From '.$from->name],
            ],
            actorUserId: $actorUserId,
            referenceType: null,
            referenceId: null,
            status: 'draft',
        );
    }

    /**
     * Record starting cash/bank for a wallet: debit wallet (asset), credit opening equity.
     */
    public function postWalletOpeningBalance(int $branchId, string $entryDate, int $walletId, float $amount, string $memo, int $actorUserId): JournalEntry
    {
        $wallet = Wallet::query()->whereKey($walletId)->whereAppliesToLocation($branchId)->firstOrFail();
        $amt = round($amount, 2);
        if ($amt <= 0) {
            throw new \InvalidArgumentException('Opening balance amount must be greater than zero.');
        }

        $equity = $this->requireAccount(LedgerAccountCodes::OPENING_BALANCE_EQUITY);
        $desc = $memo !== '' ? $memo : 'Wallet opening balance';
        $desc = Str::limit($desc, 450, '…');

        return $this->postBalanced(
            branchId: $branchId,
            entryDate: $entryDate,
            description: $desc,
            source: 'wallet_opening_balance',
            lines: [
                ['ledger_account_id' => $wallet->ledger_account_id, 'debit' => $amt, 'credit' => 0, 'line_memo' => $wallet->name],
                ['ledger_account_id' => $equity->id, 'debit' => 0, 'credit' => $amt, 'line_memo' => 'Opening balance equity'],
            ],
            actorUserId: $actorUserId,
            referenceType: null,
            referenceId: null,
            status: 'draft',
        );
    }

    private function entryExists(string $source, string $referenceType, int $referenceId): bool
    {
        return JournalEntry::query()
            ->where('source', $source)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->exists();
    }

    private function requireAccount(string $code): LedgerAccount
    {
        $acc = $this->ledgerAccountByCode($code);
        if (! $acc) {
            throw new \RuntimeException("Missing ledger account {$code}. Run database migrations.");
        }

        return $acc;
    }

    private function requireHistoricalPaymentClearingAccount(): LedgerAccount
    {
        $existing = $this->ledgerAccountByCode(LedgerAccountCodes::HISTORICAL_PAYMENT_CLEARING);
        if ($existing instanceof LedgerAccount) {
            return $existing;
        }

        return LedgerAccount::query()->create([
            'code' => LedgerAccountCodes::HISTORICAL_PAYMENT_CLEARING,
            'name' => 'Historical customer payments (no wallet)',
            'type' => 'asset',
            'is_system' => true,
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<array{ledger_account_id: int, debit: float|int, credit: float|int, line_memo?: string|null}>  $lines
     */
    private function postBalanced(
        int $branchId,
        string $entryDate,
        string $description,
        string $source,
        array $lines,
        int $actorUserId,
        ?string $referenceType = null,
        ?int $referenceId = null,
        string $status = 'posted',
    ): JournalEntry {
        return DB::transaction(function () use ($branchId, $entryDate, $description, $source, $lines, $actorUserId, $referenceType, $referenceId, $status): JournalEntry {
            $debitSum = 0.0;
            $creditSum = 0.0;
            foreach ($lines as $line) {
                $d = round((float) $line['debit'], 2);
                $c = round((float) $line['credit'], 2);
                if (($d > 0 && $c > 0) || ($d <= 0 && $c <= 0)) {
                    throw new \InvalidArgumentException('Each journal line needs exactly one of debit or credit.');
                }
                $debitSum += $d;
                $creditSum += $c;
            }

            if (abs($debitSum - $creditSum) > 0.001) {
                throw new \InvalidArgumentException('Debits must equal credits.');
            }

            if (count($lines) < 2) {
                throw new \InvalidArgumentException('A journal entry needs at least two lines.');
            }

            $entry = JournalEntry::create([
                'branch_id' => $branchId,
                'entry_date' => $entryDate,
                'document_no' => null,
                'description' => $description,
                'source' => $source,
                'status' => $status,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'created_by' => $actorUserId,
            ]);

            $entry->document_no = 'JE-'.$entryDate.'-'.$entry->id;
            $entry->save();

            foreach ($lines as $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'ledger_account_id' => (int) $line['ledger_account_id'],
                    'debit' => round((float) $line['debit'], 2),
                    'credit' => round((float) $line['credit'], 2),
                    'line_memo' => $line['line_memo'] ?? null,
                ]);
            }

            return $entry->load('lines');
        });
    }

    /**
     * @return array{
     *     source: string,
     *     entry_date: string,
     *     description: string|null,
     *     branch_id: int,
     *     wallet_id?: int,
     *     from_wallet_id?: int,
     *     to_wallet_id?: int,
     *     amount: float,
     * }
     */
    public function manualEntryFormData(JournalEntry $entry): array
    {
        if (! $entry->isEditableManualEntry()) {
            throw new \InvalidArgumentException('This journal entry cannot be edited.');
        }

        $entry->loadMissing('lines.ledgerAccount');
        $walletByLedger = Wallet::query()->get()->keyBy('ledger_account_id');

        $base = [
            'source' => $entry->source,
            'entry_date' => $entry->entry_date->toDateString(),
            'description' => $entry->description,
            'branch_id' => (int) $entry->branch_id,
        ];

        return match ($entry->source) {
            'manual_expense' => array_merge($base, $this->parseSingleWalletSide($entry, $walletByLedger, 'credit')),
            'manual_income' => array_merge($base, $this->parseSingleWalletSide($entry, $walletByLedger, 'debit')),
            'manual_transfer' => array_merge($base, $this->parseTransferWallets($entry, $walletByLedger)),
            'wallet_opening_balance' => array_merge($base, $this->parseSingleWalletSide($entry, $walletByLedger, 'debit')),
            default => throw new \InvalidArgumentException('Unsupported manual journal source.'),
        };
    }

    /**
     * @param  array{
     *     entry_date: string,
     *     amount: float,
     *     description?: string|null,
     *     wallet_id?: int,
     *     from_wallet_id?: int,
     *     to_wallet_id?: int,
     * }  $data
     */
    public function updateManualEntry(JournalEntry $entry, array $data, \App\Models\User $user): JournalEntry
    {
        if (! $entry->isEditableManualEntry()) {
            throw new \InvalidArgumentException('This journal entry cannot be edited.');
        }

        return DB::transaction(function () use ($entry, $data, $user): JournalEntry {
            $entry->loadMissing('lines');
            $walletByLedger = Wallet::query()->get()->keyBy('ledger_account_id');
            $walletLedgerIds = $walletByLedger->keys()->all();
            $amt = round((float) $data['amount'], 2);

            if ($amt <= 0) {
                throw new \InvalidArgumentException('Amount must be greater than zero.');
            }

            $lines = match ($entry->source) {
                'manual_expense' => $this->buildUpdatedExpenseLines(
                    $entry,
                    $walletByLedger,
                    $walletLedgerIds,
                    (int) $data['wallet_id'],
                    $amt,
                    $user,
                ),
                'manual_income' => $this->buildUpdatedIncomeLines(
                    $entry,
                    $walletByLedger,
                    $walletLedgerIds,
                    (int) $data['wallet_id'],
                    $amt,
                    $user,
                ),
                'manual_transfer' => $this->buildUpdatedTransferLines(
                    (int) $entry->branch_id,
                    (int) $data['from_wallet_id'],
                    (int) $data['to_wallet_id'],
                    $amt,
                    $user,
                ),
                'wallet_opening_balance' => $this->buildUpdatedOpeningBalanceLines(
                    (int) $entry->branch_id,
                    (int) $data['wallet_id'],
                    $amt,
                    $user,
                ),
                default => throw new \InvalidArgumentException('Unsupported manual journal source.'),
            };

            JournalLine::withoutGlobalScope('posted')->where('journal_entry_id', $entry->id)->delete();

            foreach ($lines as $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'ledger_account_id' => (int) $line['ledger_account_id'],
                    'debit' => round((float) $line['debit'], 2),
                    'credit' => round((float) $line['credit'], 2),
                    'line_memo' => $line['line_memo'] ?? null,
                ]);
            }

            $description = trim((string) ($data['description'] ?? ''));
            $entry->update([
                'entry_date' => $data['entry_date'],
                'description' => $description !== '' ? $description : $entry->description,
            ]);

            return $entry->fresh('lines');
        });
    }

    public function deleteManualJournalEntry(JournalEntry $entry): void
    {
        if (! $entry->isEditableManualEntry()) {
            throw new \InvalidArgumentException('This journal entry cannot be deleted.');
        }

        DB::transaction(function () use ($entry): void {
            JournalLine::withoutGlobalScope('posted')->where('journal_entry_id', $entry->id)->delete();
            $entry->delete();
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Wallet>  $walletByLedger
     * @return array{wallet_id: int, amount: float}
     */
    private function parseSingleWalletSide(JournalEntry $entry, $walletByLedger, string $side): array
    {
        $walletLine = $entry->lines->first(function (JournalLine $line) use ($walletByLedger, $side): bool {
            if (! $walletByLedger->has($line->ledger_account_id)) {
                return false;
            }

            return $side === 'credit'
                ? (float) $line->credit > 0
                : (float) $line->debit > 0;
        });

        if ($walletLine === null) {
            throw new \InvalidArgumentException('Could not locate wallet line on this entry.');
        }

        $wallet = $walletByLedger->get($walletLine->ledger_account_id);
        $amount = $side === 'credit' ? (float) $walletLine->credit : (float) $walletLine->debit;

        return [
            'wallet_id' => (int) $wallet->id,
            'amount' => round($amount, 2),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Wallet>  $walletByLedger
     * @return array{from_wallet_id: int, to_wallet_id: int, amount: float}
     */
    private function parseTransferWallets(JournalEntry $entry, $walletByLedger): array
    {
        $fromLine = $entry->lines->first(fn (JournalLine $line): bool => $walletByLedger->has($line->ledger_account_id) && (float) $line->credit > 0);
        $toLine = $entry->lines->first(fn (JournalLine $line): bool => $walletByLedger->has($line->ledger_account_id) && (float) $line->debit > 0);

        if ($fromLine === null || $toLine === null) {
            throw new \InvalidArgumentException('Could not locate transfer wallet lines on this entry.');
        }

        return [
            'from_wallet_id' => (int) $walletByLedger->get($fromLine->ledger_account_id)->id,
            'to_wallet_id' => (int) $walletByLedger->get($toLine->ledger_account_id)->id,
            'amount' => round((float) $toLine->debit, 2),
        ];
    }

    /**
     * @param  list<int>  $walletLedgerIds
     * @return list<array{ledger_account_id: int, debit: float, credit: float, line_memo?: string|null}>
     */
    private function buildUpdatedExpenseLines(
        JournalEntry $entry,
        $walletByLedger,
        array $walletLedgerIds,
        int $walletId,
        float $amount,
        \App\Models\User $user,
    ): array {
        $wallet = Wallet::query()->whereKey($walletId)->whereAppliesToLocation((int) $entry->branch_id)->firstOrFail();
        OwnerCapitalWallet::assertExpenseFrom($user, $wallet, 'wallet_id');

        $counterparty = $entry->lines->reject(fn (JournalLine $line): bool => in_array($line->ledger_account_id, $walletLedgerIds, true));
        $scaled = $this->scaleCounterpartyLines($counterparty, $amount, 'debit');

        $scaled[] = [
            'ledger_account_id' => $wallet->ledger_account_id,
            'debit' => 0,
            'credit' => $amount,
            'line_memo' => $wallet->name,
        ];

        return $scaled;
    }

    /**
     * @param  list<int>  $walletLedgerIds
     * @return list<array{ledger_account_id: int, debit: float, credit: float, line_memo?: string|null}>
     */
    private function buildUpdatedIncomeLines(
        JournalEntry $entry,
        $walletByLedger,
        array $walletLedgerIds,
        int $walletId,
        float $amount,
        \App\Models\User $user,
    ): array {
        $wallet = Wallet::query()->whereKey($walletId)->whereAppliesToLocation((int) $entry->branch_id)->firstOrFail();

        $counterparty = $entry->lines->reject(fn (JournalLine $line): bool => in_array($line->ledger_account_id, $walletLedgerIds, true));
        $scaled = $this->scaleCounterpartyLines($counterparty, $amount, 'credit');

        array_unshift($scaled, [
            'ledger_account_id' => $wallet->ledger_account_id,
            'debit' => $amount,
            'credit' => 0,
            'line_memo' => $wallet->name,
        ]);

        return $scaled;
    }

    /**
     * @return list<array{ledger_account_id: int, debit: float, credit: float, line_memo?: string|null}>
     */
    private function buildUpdatedTransferLines(int $branchId, int $fromWalletId, int $toWalletId, float $amount, \App\Models\User $user): array
    {
        $from = Wallet::query()->whereKey($fromWalletId)->whereAppliesToLocation($branchId)->firstOrFail();
        $to = Wallet::query()->whereKey($toWalletId)->whereAppliesToLocation($branchId)->firstOrFail();

        if ($from->id === $to->id) {
            throw new \InvalidArgumentException('Source and destination wallets must differ.');
        }

        OwnerCapitalWallet::assertTransferFrom($user, $from, 'from_wallet_id');

        return [
            [
                'ledger_account_id' => $to->ledger_account_id,
                'debit' => $amount,
                'credit' => 0,
                'line_memo' => 'To '.$to->name,
            ],
            [
                'ledger_account_id' => $from->ledger_account_id,
                'debit' => 0,
                'credit' => $amount,
                'line_memo' => 'From '.$from->name,
            ],
        ];
    }

    /**
     * @return list<array{ledger_account_id: int, debit: float, credit: float, line_memo?: string|null}>
     */
    private function buildUpdatedOpeningBalanceLines(int $branchId, int $walletId, float $amount, \App\Models\User $user): array
    {
        $wallet = Wallet::query()->whereKey($walletId)->whereAppliesToLocation($branchId)->firstOrFail();
        OwnerCapitalWallet::assertOpeningBalance($user, $wallet, 'wallet_id');

        $equity = $this->requireAccount(LedgerAccountCodes::OPENING_BALANCE_EQUITY);

        return [
            [
                'ledger_account_id' => $wallet->ledger_account_id,
                'debit' => $amount,
                'credit' => 0,
                'line_memo' => $wallet->name,
            ],
            [
                'ledger_account_id' => $equity->id,
                'debit' => 0,
                'credit' => $amount,
                'line_memo' => 'Opening balance equity',
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, JournalLine>  $counterpartyLines
     * @return list<array{ledger_account_id: int, debit: float, credit: float, line_memo?: string|null}>
     */
    private function scaleCounterpartyLines($counterpartyLines, float $newTotal, string $amountSide): array
    {
        if ($counterpartyLines->isEmpty()) {
            throw new \InvalidArgumentException('Entry has no counterparty lines to update.');
        }

        $oldTotal = (float) $counterpartyLines->sum(fn (JournalLine $line): float => max((float) $line->debit, (float) $line->credit));
        if ($oldTotal <= 0) {
            throw new \InvalidArgumentException('Counterparty total must be greater than zero.');
        }

        $lines = [];
        $remaining = $newTotal;
        $count = $counterpartyLines->count();
        $index = 0;

        foreach ($counterpartyLines as $line) {
            $index++;
            $oldAmt = max((float) $line->debit, (float) $line->credit);
            $newAmt = $index === $count
                ? round($remaining, 2)
                : round($oldAmt * ($newTotal / $oldTotal), 2);
            $remaining -= $newAmt;

            $lines[] = [
                'ledger_account_id' => (int) $line->ledger_account_id,
                'debit' => $amountSide === 'debit' ? $newAmt : 0,
                'credit' => $amountSide === 'credit' ? $newAmt : 0,
                'line_memo' => $line->line_memo,
            ];
        }

        return $lines;
    }
}
