<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\SaleLineSerial;
use App\Models\SalesInvoice;
use App\Models\SerialItem;
use App\Models\StockLedger;
use Illuminate\Support\Facades\DB;

class SalesInvoiceBranchChangeService
{
    public function __construct(
        private readonly InventoryBalanceService $inventoryBalanceService,
    ) {}

    public function changeBranch(SalesInvoice $invoice, int $newBranchId, int $actorUserId): void
    {
        DB::transaction(function () use ($invoice, $newBranchId, $actorUserId): void {
            $invoiceModel = SalesInvoice::query()
                ->lockForUpdate()
                ->with(['lines.saleLineSerials.serialItem'])
                ->findOrFail($invoice->id);

            $oldBranchId = (int) $invoiceModel->branch_id;

            if ($oldBranchId === $newBranchId) {
                return;
            }

            $beforeSnapshot = [
                'branch_id' => $oldBranchId,
                'status' => $invoiceModel->status,
            ];

            if ($invoiceModel->status === 'draft') {
                $this->releaseDraftInvoiceSerials($invoiceModel);
                $invoiceModel->update(['branch_id' => $newBranchId]);
                $this->auditBranchChange($invoiceModel, $beforeSnapshot, $actorUserId);

                return;
            }

            if (! in_array($invoiceModel->status, ['posted', 'active'], true)) {
                throw new InvalidStateException('Only draft, posted, or active invoices can change branch.');
            }

            $touchedProductIds = [];

            foreach ($invoiceModel->lines as $line) {
                $touchedProductIds[(int) $line->product_id] = true;

                foreach ($line->saleLineSerials as $lineSerial) {
                    $serial = SerialItem::query()->lockForUpdate()->find($lineSerial->serial_item_id);
                    if (! $serial) {
                        continue;
                    }

                    if ((int) $serial->current_location_id !== $oldBranchId) {
                        throw new InvalidStateException(
                            'Cannot change branch: serial '.$serial->serial_number.' is no longer at the invoice branch.'
                        );
                    }

                    if (! in_array($serial->status, ['reserved', 'sold'], true)) {
                        throw new InvalidStateException(
                            'Cannot change branch: serial '.$serial->serial_number.' is not in a movable state.'
                        );
                    }

                    $serial->update(['current_location_id' => $newBranchId]);
                }
            }

            StockLedger::query()
                ->where('reference_id', $invoiceModel->id)
                ->where(function ($query): void {
                    $query->where('reference_type', 'SalesInvoice')
                        ->orWhere('reference_type', SalesInvoice::class);
                })
                ->update(['location_id' => $newBranchId]);

            JournalEntry::query()
                ->where('source', 'sales_invoice')
                ->where('reference_id', $invoiceModel->id)
                ->where(function ($query): void {
                    $query->where('reference_type', SalesInvoice::class)
                        ->orWhere('reference_type', 'SalesInvoice');
                })
                ->update(['branch_id' => $newBranchId]);

            $invoiceModel->update(['branch_id' => $newBranchId]);

            foreach (array_keys($touchedProductIds) as $productId) {
                $this->inventoryBalanceService->recalculate((int) $productId, $oldBranchId);
                $this->inventoryBalanceService->recalculate((int) $productId, $newBranchId);
            }

            $this->auditBranchChange($invoiceModel->fresh(), $beforeSnapshot, $actorUserId);
        });
    }

    private function releaseDraftInvoiceSerials(SalesInvoice $invoice): void
    {
        foreach ($invoice->lines as $line) {
            foreach ($line->saleLineSerials as $sls) {
                $serial = $sls->serialItem;
                if ($serial && ($serial->status === 'reserved' || (int) $serial->sale_invoice_line_id === (int) $line->id)) {
                    $serial->update([
                        'status' => 'in_stock',
                        'sale_invoice_line_id' => null,
                        'warranty_start_date' => null,
                        'warranty_end_date' => null,
                    ]);
                }
            }

            SaleLineSerial::query()->where('sales_invoice_line_id', $line->id)->delete();
        }
    }

    /**
     * @param  array{branch_id: int, status: string}  $beforeSnapshot
     */
    private function auditBranchChange(SalesInvoice $invoice, array $beforeSnapshot, int $actorUserId): void
    {
        AuditLog::create([
            'actor_user_id' => $actorUserId,
            'action' => 'sales_invoice.branch_changed',
            'entity_type' => 'SalesInvoice',
            'entity_id' => $invoice->id,
            'before_snapshot' => $beforeSnapshot,
            'after_snapshot' => [
                'branch_id' => (int) $invoice->branch_id,
                'status' => $invoice->status,
            ],
            'ip_address' => request()->ip(),
        ]);
    }
}
