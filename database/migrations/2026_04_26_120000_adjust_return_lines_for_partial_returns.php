<?php

use App\Models\ReturnLine;
use App\Models\ReturnOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_lines', function (Blueprint $table): void {
            $table->unsignedInteger('qty')->nullable()->after('serial_item_id');
        });

        Schema::table('return_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('serial_item_id')->nullable()->change();
        });

        $this->backfillDraftReturnLines();
    }

    public function down(): void
    {
        Schema::table('return_lines', function (Blueprint $table): void {
            $table->dropColumn('qty');
        });
    }

    private function backfillDraftReturnLines(): void
    {
        ReturnOrder::query()
            ->where('status', 'draft')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('return_lines')
                    ->whereColumn('return_lines.return_order_id', 'return_orders.id');
            })
            ->with(['salesInvoice.lines.product', 'salesInvoice.lines.saleLineSerials'])
            ->lazyById(100)
            ->each(function (ReturnOrder $return): void {
                $invoice = $return->salesInvoice;
                if (! $invoice) {
                    return;
                }
                foreach ($invoice->lines as $line) {
                    $isSerialized = (bool) ($line->product?->is_serialized ?? true);
                    if ($isSerialized) {
                        foreach ($line->saleLineSerials as $sls) {
                            ReturnLine::create([
                                'return_order_id' => $return->id,
                                'sales_invoice_line_id' => $line->id,
                                'serial_item_id' => $sls->serial_item_id,
                                'qty' => null,
                                'resolution' => 'restock',
                                'condition_notes' => null,
                            ]);
                        }
                    } else {
                        ReturnLine::create([
                            'return_order_id' => $return->id,
                            'sales_invoice_line_id' => $line->id,
                            'serial_item_id' => null,
                            'qty' => (int) $line->qty,
                            'resolution' => 'restock',
                            'condition_notes' => null,
                        ]);
                    }
                }
            });
    }
};
