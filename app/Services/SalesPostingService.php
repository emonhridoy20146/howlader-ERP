<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Exceptions\SerialCountMismatchException;
use App\Exceptions\SerialLocationMismatchException;
use App\Exceptions\SerialNotAvailableException;
use App\Exceptions\SerialSaleLedgerIntegrityException;
use App\Models\AccountsReceivableLedger;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SaleLineSerial;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SerialItem;
use App\Models\StockLedger;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SalesPostingService
{
    public function __construct(
        private readonly InventoryBalanceService $inventoryBalanceService,
        private readonly PaymentService $paymentService,
        private readonly JournalService $journalService,
    ) {}

    public function post(SalesInvoice $invoice, int $actorUserId, array $payments = []): void
    {
        DB::transaction(function () use ($invoice, $actorUserId): void {
            $invoice->load('lines.saleLineSerials', 'lines');
            $beforeSnapshot = [
                'status' => $invoice->status,
                'amount_paid' => $invoice->amount_paid,
                'amount_due' => $invoice->amount_due,
                'posted_at' => optional($invoice->posted_at)?->toDateTimeString(),
            ];

            if ($invoice->status !== 'draft') {
                throw new InvalidStateException('Invoice already posted.');
            }

            $ledgerTime = Carbon::now();

            $products = Product::query()
                ->whereIn('id', $invoice->lines->pluck('product_id')->unique())
                ->get()
                ->keyBy('id');

            foreach ($invoice->lines as $line) {
                $product = $products->get($line->product_id);
                $isSerialized = (bool) ($product?->is_serialized ?? true);

                if ($isSerialized && $line->saleLineSerials->count() !== $line->qty) {
                    throw new SerialCountMismatchException('Line '.$line->id.' serial count mismatch.');
                }

                if ($isSerialized) {
                    foreach ($line->saleLineSerials as $sls) {
                        $serial = SerialItem::lockForUpdate()->findOrFail($sls->serial_item_id);
                        $this->finalizeSerializedSale($serial, $line, $invoice, $actorUserId, $ledgerTime);
                    }
                } else {
                    $this->issueNonSerializedSaleLine($line, $invoice, $actorUserId, $ledgerTime);
                }

                $this->inventoryBalanceService->recalculate($line->product_id, $invoice->branch_id);
            }

            $this->assertSoldSerialsBackedBySaleIssueLedgerRows($invoice);

            $initialAmountDue = round((float) $invoice->grand_total, 2);
            if ($invoice->sale_type === 'installment') {
                $downPayment = round(max(0, (float) ($invoice->installment_down_payment ?? 0)), 2);
                $initialAmountDue = max(0, round($initialAmountDue - $downPayment, 2));
            }

            $initialPaymentStatus = $initialAmountDue <= 0.001 ? 'paid' : 'unpaid';

            $invoice->update([
                'status' => $invoice->sale_type === 'installment' ? 'active' : 'posted',
                'payment_status' => $initialPaymentStatus,
                'posted_by' => $actorUserId,
                'posted_at' => $ledgerTime,
                'amount_due' => $initialAmountDue,
            ]);

            AccountsReceivableLedger::create([
                'customer_id' => $invoice->customer_id,
                'event_time' => $ledgerTime,
                'event_type' => 'invoice_posted',
                'amount_delta' => $invoice->grand_total,
                'reference_type' => 'SalesInvoice',
                'reference_id' => $invoice->id,
                'actor_user_id' => $actorUserId,
            ]);

            Customer::where('id', $invoice->customer_id)->increment('outstanding_balance', $invoice->grand_total);

            $invoice->refresh();

            $this->journalService->postSalesInvoiceGl($invoice->fresh(), $actorUserId);

            $invoice->refresh();
            AuditLog::create([
                'actor_user_id' => $actorUserId,
                'action' => 'sales_invoice.posted',
                'entity_type' => 'SalesInvoice',
                'entity_id' => $invoice->id,
                'before_snapshot' => $beforeSnapshot,
                'after_snapshot' => [
                    'status' => $invoice->status,
                    'amount_paid' => $invoice->amount_paid,
                    'amount_due' => $invoice->amount_due,
                    'posted_at' => optional($invoice->posted_at)?->toDateTimeString(),
                ],
                'ip_address' => request()->ip(),
            ]);
        });

        foreach ($payments as $paymentData) {
            $this->paymentService->recordAndAllocate($invoice->fresh(), $paymentData, $actorUserId);
        }

        $invoice = $invoice->fresh();
        if ((float) $invoice->amount_due <= 0.001) {
            $this->paymentService->assignInvoiceNumberWhenCollected($invoice);
        }
    }

    /**
     * Mark serial sold + write matching sale_issue row (canonical path for UI posting, revisions, imports).
     * Caller must run inside a DB transaction and hold/pass a locked {@see SerialItem} when concurrency matters.
     */
    public function recordSerializedUnitSale(
        SerialItem $serial,
        SalesInvoiceLine $line,
        SalesInvoice $invoice,
        int $actorUserId,
        CarbonInterface $ledgerEventTime,
    ): void {
        $this->finalizeSerializedSale($serial, $line, $invoice, $actorUserId, Carbon::parse($ledgerEventTime));
    }

    /**
     * Undo sale-issue stock movements for a posted/active invoice (restore serials, remove sale_issue ledger rows, delete lines).
     *
     * @return list<int> Product IDs that had stock touched on this invoice branch
     */
    public function reversePostedSaleStock(SalesInvoice $invoice): array
    {
        $invoice->loadMissing('lines.saleLineSerials.serialItem');

        $branchId = (int) $invoice->branch_id;
        $touchedProductIds = [];

        foreach ($invoice->lines as $line) {
            $touchedProductIds[(int) $line->product_id] = true;

            foreach ($line->saleLineSerials as $lineSerial) {
                $serial = SerialItem::query()->lockForUpdate()->find($lineSerial->serial_item_id);
                if (! $serial) {
                    continue;
                }

                if ((int) $serial->current_location_id !== $branchId) {
                    throw new InvalidStateException(
                        'Cannot revise invoice: serial '.$serial->serial_number.' is no longer at this invoice branch.'
                    );
                }

                if (! in_array($serial->status, ['reserved', 'sold'], true)) {
                    throw new InvalidStateException(
                        'Cannot revise invoice: serial '.$serial->serial_number.' is not in a reversible state.'
                    );
                }

                $serial->update([
                    'status' => 'in_stock',
                    'sale_invoice_line_id' => null,
                    'warranty_start_date' => null,
                    'warranty_end_date' => null,
                ]);
            }
        }

        StockLedger::query()
            ->where('reference_id', $invoice->id)
            ->where(function ($q): void {
                $q->where('reference_type', 'SalesInvoice')
                    ->orWhere('reference_type', SalesInvoice::class);
            })
            ->delete();

        $lineIds = $invoice->lines->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($lineIds !== []) {
            SaleLineSerial::query()->whereIn('sales_invoice_line_id', $lineIds)->delete();
            SalesInvoiceLine::query()->whereIn('id', $lineIds)->delete();
        }

        return array_keys($touchedProductIds);
    }

    /**
     * Apply sale-issue stock movements for invoice lines (posted/active): reserved/in_stock serials → sold, stock ledger rows.
     */
    public function applyPostedSaleStock(SalesInvoice $invoice, int $actorUserId): void
    {
        $invoice->load('lines.saleLineSerials', 'lines');

        $ledgerTime = Carbon::now();

        $products = Product::query()
            ->whereIn('id', $invoice->lines->pluck('product_id')->unique())
            ->get()
            ->keyBy('id');

        foreach ($invoice->lines as $line) {
            $product = $products->get($line->product_id);
            $isSerialized = (bool) ($product?->is_serialized ?? true);

            if ($isSerialized && $line->saleLineSerials->count() !== $line->qty) {
                throw new SerialCountMismatchException('Line '.$line->id.' serial count mismatch.');
            }

            if ($isSerialized) {
                foreach ($line->saleLineSerials as $sls) {
                    $serial = SerialItem::lockForUpdate()->findOrFail($sls->serial_item_id);
                    $this->finalizeSerializedSale($serial, $line, $invoice, $actorUserId, $ledgerTime);
                }
            } else {
                $this->issueNonSerializedSaleLine($line, $invoice, $actorUserId, $ledgerTime);
            }

            $this->inventoryBalanceService->recalculate((int) $line->product_id, (int) $invoice->branch_id);
        }

        $this->assertSoldSerialsBackedBySaleIssueLedgerRows($invoice);
    }

    private function finalizeSerializedSale(
        SerialItem $serial,
        SalesInvoiceLine $line,
        SalesInvoice $invoice,
        int $actorUserId,
        Carbon $ledgerTime,
    ): void {
        $locked = SerialItem::query()->lockForUpdate()->findOrFail($serial->id);

        if ((int) $locked->current_location_id !== (int) $invoice->branch_id) {
            throw new SerialLocationMismatchException($locked->serial_number);
        }

        if (! in_array($locked->status, ['in_stock', 'reserved'], true)) {
            throw new SerialNotAvailableException($locked->serial_number);
        }

        $start = Carbon::parse($ledgerTime)->toDateString();
        $end = Carbon::parse($ledgerTime)->copy()->addMonths((int) $line->warranty_months_snapshot)->toDateString();

        $locked->update([
            'status' => 'sold',
            'sale_invoice_line_id' => $line->id,
            'warranty_start_date' => $start,
            'warranty_end_date' => $end,
        ]);

        StockLedger::create([
            'event_time' => $ledgerTime,
            'product_id' => $line->product_id,
            'serial_item_id' => $locked->id,
            'location_id' => $invoice->branch_id,
            'movement_type' => 'sale_issue',
            'quantity_delta' => -1,
            'unit_cost' => $locked->purchase_cost,
            'reference_type' => 'SalesInvoice',
            'reference_id' => $invoice->id,
            'actor_user_id' => $actorUserId,
        ]);
    }

    /** Import / external callers: verifies serialized invoice lines match sale_issue totals (throws ⇒ rollback transaction). */
    public function enforceSerializedSaleLedgerInvariant(SalesInvoice $invoice): void
    {
        $this->assertSoldSerialsBackedBySaleIssueLedgerRows($invoice);
    }

    private function issueNonSerializedSaleLine(
        SalesInvoiceLine $line,
        SalesInvoice $invoice,
        int $actorUserId,
        Carbon $ledgerTime,
    ): void {
        StockLedger::create([
            'event_time' => $ledgerTime,
            'product_id' => $line->product_id,
            'serial_item_id' => null,
            'location_id' => $invoice->branch_id,
            'movement_type' => 'sale_issue',
            'quantity_delta' => -1 * (int) $line->qty,
            'unit_cost' => null,
            'reference_type' => 'SalesInvoice',
            'reference_id' => $invoice->id,
            'actor_user_id' => $actorUserId,
        ]);
    }

    /**
     * Fails fast if serialized lines do not each have exactly one net sale_issue unit on this invoice.
     */
    private function assertSoldSerialsBackedBySaleIssueLedgerRows(SalesInvoice $invoice): void
    {
        $invoice->loadMissing('lines.saleLineSerials');

        $products = Product::query()
            ->whereIn('id', $invoice->lines->pluck('product_id')->unique())
            ->get()
            ->keyBy('id');

        foreach ($invoice->lines as $line) {
            $product = $products->get($line->product_id);
            if (! ($product?->is_serialized ?? true)) {
                continue;
            }

            foreach ($line->saleLineSerials as $sls) {
                $sid = (int) $sls->serial_item_id;
                $serial = SerialItem::query()->find($sid);
                if (! $serial) {
                    throw new SerialSaleLedgerIntegrityException(
                        'Serialized sale line '.$line->id.' references missing serial '.$sid.'. Stock posting aborted.'
                    );
                }

                if ($serial->status !== 'sold' || (int) $serial->sale_invoice_line_id !== (int) $line->id) {
                    throw new SerialSaleLedgerIntegrityException(
                        'Serial '.$serial->serial_number.' (id '.$serial->id.') is not marked sold against line '.$line->id.'. Stock posting aborted.'
                    );
                }

                $net = (int) StockLedger::query()
                    ->where('serial_item_id', $sid)
                    ->where('movement_type', 'sale_issue')
                    ->where('reference_id', $invoice->id)
                    ->where(function ($q): void {
                        $q->where('reference_type', 'SalesInvoice')
                            ->orWhere('reference_type', SalesInvoice::class);
                    })
                    ->sum('quantity_delta');

                if ($net !== -1) {
                    throw new SerialSaleLedgerIntegrityException(
                        'Serial '.$serial->serial_number.' (id '.$serial->id.') must have sale_issue quantity -1 '
                        .'for invoice '.$invoice->invoice_number.' (found '.$net.'). Stock posting aborted.'
                    );
                }
            }
        }
    }
}
