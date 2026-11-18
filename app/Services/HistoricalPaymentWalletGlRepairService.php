<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Models\Wallet;
use App\Support\LedgerAccountCodes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class HistoricalPaymentWalletGlRepairService
{
    public function __construct(
        private readonly JournalService $journalService,
    ) {}

    /**
     * @return Builder<Payment>
     */
    public function candidateQuery(?string $paymentNeedle = null, ?string $invoiceNeedle = null): Builder
    {
        $query = Payment::query()
            ->where('direction', 'inbound')
            ->where('notes', 'like', Payment::HISTORICAL_PAYMENT_NOTE_PREFIX.'%')
            ->whereHas('paymentAllocations.salesInvoice', function (Builder $invoiceQuery): void {
                $invoiceQuery->whereIn('status', ['posted', 'active']);
            })
            ->with([
                'paymentAllocations.salesInvoice.branch',
            ])
            ->orderBy('id');

        if ($paymentNeedle !== null && $paymentNeedle !== '') {
            if (is_numeric($paymentNeedle)) {
                $query->where(function (Builder $q) use ($paymentNeedle): void {
                    $q->whereKey((int) $paymentNeedle)
                        ->orWhere('payment_number', $paymentNeedle);
                });
            } else {
                $query->where('payment_number', $paymentNeedle);
            }
        }

        if ($invoiceNeedle !== null && $invoiceNeedle !== '') {
            $query->whereHas('paymentAllocations.salesInvoice', function (Builder $invoiceQuery) use ($invoiceNeedle): void {
                if (is_numeric($invoiceNeedle)) {
                    $invoiceQuery->where(function (Builder $q) use ($invoiceNeedle): void {
                        $q->whereKey((int) $invoiceNeedle)
                            ->orWhere('invoice_number', $invoiceNeedle);
                    });
                } else {
                    $invoiceQuery->where('invoice_number', $invoiceNeedle);
                }
            });
        }

        return $query;
    }

    /**
     * @return array{status: string, reason: ?string, wallet_id: ?int}
     */
    public function repairPayment(Payment $payment, int $actorUserId, bool $dryRun = false): array
    {
        if ($payment->skipsWalletGl()) {
            return ['status' => 'skipped', 'reason' => 'skip_wallet_gl', 'wallet_id' => null];
        }

        if (! $payment->isHistoricalPayment()) {
            return ['status' => 'skipped', 'reason' => 'not_historical', 'wallet_id' => null];
        }

        $invoice = $this->resolveInvoice($payment);
        if (! $invoice instanceof SalesInvoice) {
            return ['status' => 'skipped', 'reason' => 'no_invoice', 'wallet_id' => null];
        }

        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return ['status' => 'skipped', 'reason' => 'zero_amount', 'wallet_id' => null];
        }

        $paymentMethodId = (int) ($payment->payment_method_id ?? 0);
        if ($paymentMethodId <= 0) {
            return ['status' => 'skipped', 'reason' => 'missing_payment_method', 'wallet_id' => null];
        }

        $wallet = $this->journalService->resolveWalletForPayment((int) $invoice->branch_id, $paymentMethodId);
        if (! $wallet instanceof Wallet) {
            return ['status' => 'skipped', 'reason' => 'no_wallet_map', 'wallet_id' => null];
        }

        $clearing = LedgerAccount::query()->where('code', LedgerAccountCodes::HISTORICAL_PAYMENT_CLEARING)->first();
        $ar = LedgerAccount::query()->where('code', LedgerAccountCodes::ACCOUNTS_RECEIVABLE)->first();
        if (! $clearing instanceof LedgerAccount || ! $ar instanceof LedgerAccount) {
            return ['status' => 'skipped', 'reason' => 'missing_ledger_accounts', 'wallet_id' => null];
        }

        $breakdown = $this->journalBreakdown($payment, $wallet, $clearing, $ar, $amount);

        if ($breakdown['state'] !== 'missing') {
            return $this->skipExistingJournal($payment, $wallet, $breakdown, $dryRun);
        }

        if ($dryRun) {
            return ['status' => 'would_create', 'reason' => 'missing', 'wallet_id' => $wallet->id];
        }

        return DB::transaction(function () use ($payment, $invoice, $actorUserId): array {
            $postedWallet = $this->journalService->postHistoricalPaymentToWalletGl(
                (int) $invoice->branch_id,
                round((float) $payment->amount, 2),
                (int) $payment->payment_method_id,
                $payment->fresh(),
                $actorUserId,
            );

            if (! $postedWallet instanceof Wallet) {
                return ['status' => 'failed', 'reason' => 'journal_not_posted', 'wallet_id' => null];
            }

            $payment->update(['wallet_id' => $postedWallet->id]);

            return ['status' => 'created', 'reason' => 'missing', 'wallet_id' => $postedWallet->id];
        });
    }

    /**
     * @param  array{state: string, wallet_debit: float}  $breakdown
     * @return array{status: string, reason: ?string, wallet_id: ?int}
     */
    private function skipExistingJournal(Payment $payment, Wallet $wallet, array $breakdown, bool $dryRun): array
    {
        $walletVisible = $this->amountMatches($breakdown['wallet_debit'], round((float) $payment->amount, 2));
        $reason = match ($breakdown['state']) {
            'ok' => 'existing_wallet_journal',
            'wallet_ar' => 'existing_wallet_journal',
            default => 'existing_journal_entry',
        };

        if ($dryRun) {
            return [
                'status' => 'skipped',
                'reason' => $walletVisible && ! $payment->wallet_id ? 'would_set_wallet_id' : $reason,
                'wallet_id' => $walletVisible ? $wallet->id : null,
            ];
        }

        if ($walletVisible && (int) $payment->wallet_id !== (int) $wallet->id) {
            $payment->update(['wallet_id' => $wallet->id]);
        }

        return [
            'status' => 'skipped',
            'reason' => $reason,
            'wallet_id' => $walletVisible ? $wallet->id : null,
        ];
    }

    private function resolveInvoice(Payment $payment): ?SalesInvoice
    {
        $allocation = $payment->paymentAllocations->first();

        return $allocation?->salesInvoice;
    }

    /**
     * @return array{state: string, wallet_debit: float, clearing_credit: float, ar_credit: float, clearing_debit: float}
     */
    private function journalBreakdown(
        Payment $payment,
        Wallet $wallet,
        LedgerAccount $clearing,
        LedgerAccount $ar,
        float $expectedAmount,
    ): array {
        $entry = $this->journalService->salesPaymentJournalEntry($payment);
        if (! $entry instanceof JournalEntry) {
            return [
                'state' => 'missing',
                'wallet_debit' => 0.0,
                'clearing_credit' => 0.0,
                'ar_credit' => 0.0,
                'clearing_debit' => 0.0,
            ];
        }

        $walletDebit = $this->sumLine($entry, (int) $wallet->ledger_account_id, 'debit');
        $clearingCredit = $this->sumLine($entry, (int) $clearing->id, 'credit');
        $clearingDebit = $this->sumLine($entry, (int) $clearing->id, 'debit');
        $arCredit = $this->sumLine($entry, (int) $ar->id, 'credit');

        if ($this->amountMatches($walletDebit, $expectedAmount)
            && $this->amountMatches($clearingCredit, $expectedAmount)
            && $clearingDebit <= 0.009
            && $arCredit <= 0.009) {
            return [
                'state' => 'ok',
                'wallet_debit' => $walletDebit,
                'clearing_credit' => $clearingCredit,
                'ar_credit' => $arCredit,
                'clearing_debit' => $clearingDebit,
            ];
        }

        if ($this->amountMatches($walletDebit, $expectedAmount)
            && $this->amountMatches($arCredit, $expectedAmount)
            && $clearingCredit <= 0.009
            && $clearingDebit <= 0.009) {
            return [
                'state' => 'wallet_ar',
                'wallet_debit' => $walletDebit,
                'clearing_credit' => $clearingCredit,
                'ar_credit' => $arCredit,
                'clearing_debit' => $clearingDebit,
            ];
        }

        if ($walletDebit <= 0.009 && $this->amountMatches($clearingDebit, $expectedAmount)) {
            return [
                'state' => 'clearing_only',
                'wallet_debit' => $walletDebit,
                'clearing_credit' => $clearingCredit,
                'ar_credit' => $arCredit,
                'clearing_debit' => $clearingDebit,
            ];
        }

        return [
            'state' => 'unexpected',
            'wallet_debit' => $walletDebit,
            'clearing_credit' => $clearingCredit,
            'ar_credit' => $arCredit,
            'clearing_debit' => $clearingDebit,
        ];
    }

    private function sumLine(JournalEntry $entry, int $ledgerAccountId, string $column): float
    {
        return round((float) $entry->lines
            ->where('ledger_account_id', $ledgerAccountId)
            ->sum($column), 2);
    }

    private function amountMatches(float $actual, float $expected): bool
    {
        return abs($actual - $expected) <= 0.009;
    }
}
