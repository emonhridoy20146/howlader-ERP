<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\StockTransferSerial;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Services\InventoryBalanceService;
use App\Services\TransferIssueService;
use App\Services\TransferReceiveService;
use App\Services\StockTransferApprovalService;
use App\Support\SerialLabelCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class StockTransferController extends Controller
{
    use AppliesInventoryLocationContext;

    public function __construct(
        private readonly DocumentNumberGenerator $documentNumbers,
        private readonly StockTransferApprovalService $approvalService,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'status' => (string) $request->input('status', ''),
            'source_location_id' => (string) $request->input('source_location_id', ''),
            'destination_location_id' => (string) $request->input('destination_location_id', ''),
        ];

        $baseQuery = StockTransfer::query()
            ->with(['sourceLocation', 'destinationLocation'])
            ->withSum('lines', 'qty_requested')
            ->when($filters['q'] !== '', fn ($query) => $query->where('transfer_number', 'like', "%{$filters['q']}%"))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['source_location_id'] !== '', fn ($query) => $query->where('source_location_id', (int) $filters['source_location_id']))
            ->when($filters['destination_location_id'] !== '', fn ($query) => $query->where('destination_location_id', (int) $filters['destination_location_id']))
            ->when(
                $filters['source_location_id'] === '' && $filters['destination_location_id'] === '' && $this->contextLocationId() !== null,
                fn ($query) => $this->scopeContextTransfer($query)
            );

        $transfers = (clone $baseQuery)
            ->latest()
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $kpiLocationScope = StockTransfer::query()
            ->when($filters['source_location_id'] !== '', fn ($query) => $query->where('source_location_id', (int) $filters['source_location_id']))
            ->when($filters['destination_location_id'] !== '', fn ($query) => $query->where('destination_location_id', (int) $filters['destination_location_id']))
            ->when(
                $filters['source_location_id'] === '' && $filters['destination_location_id'] === '' && $this->contextLocationId() !== null,
                fn ($query) => $this->scopeContextTransfer($query)
            );

        $kpis = [
            'total' => (clone $kpiLocationScope)->count(),
            'approved' => (clone $kpiLocationScope)->where('status', 'approved')->count(),
            'issued' => (clone $kpiLocationScope)->where('status', 'issued')->count(),
            'received' => (clone $kpiLocationScope)->where('status', 'received')->count(),
            'filtered' => $transfers->total(),
        ];

        $filterLocations = Location::orderBy('name')->get(['id', 'name', 'code']);

        return view('transfers.index', compact('transfers', 'filters', 'kpis', 'filterLocations'));
    }

    public function show(StockTransfer $transfer): View
    {
        $this->authorize('view', $transfer);

        $transfer->load([
            'sourceLocation',
            'destinationLocation',
            'lines.product',
            'transferSerials' => fn ($q) => $q->orderBy('id')->with(['serialItem', 'product']),
        ]);

        $actorIds = array_values(array_unique(array_filter([
            $transfer->requested_by,
            $transfer->source_approved_by,
            $transfer->destination_approved_by,
            $transfer->approved_by,
            $transfer->issued_by,
            $transfer->received_by,
        ])));
        $actors = $actorIds === []
            ? collect()
            : User::query()->whereIn('id', $actorIds)->get(['id', 'name'])->keyBy('id');

        return view('transfers.show', compact('transfer', 'actors'));
    }

    public function receipt(StockTransfer $transfer): View
    {
        $this->authorize('view', $transfer);

        $transfer->load([
            'sourceLocation',
            'destinationLocation',
            'lines.product',
            'transferSerials' => fn ($q) => $q->orderBy('id')->with(['serialItem', 'product']),
        ]);

        $actorIds = array_values(array_unique(array_filter([
            $transfer->requested_by,
            $transfer->source_approved_by,
            $transfer->destination_approved_by,
            $transfer->approved_by,
            $transfer->issued_by,
            $transfer->received_by,
        ])));
        $actors = $actorIds === []
            ? collect()
            : User::query()->whereIn('id', $actorIds)->get(['id', 'name'])->keyBy('id');

        return view('transfers.receipt', [
            'transfer' => $transfer,
            'actors' => $actors,
            'printedAt' => now(),
            'companyPad' => $this->companyPadSettings(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', StockTransfer::class);

        return view('transfers.create', [
            'locations' => Location::where('is_active', true)->orderBy('name')->get(),
            'transfer' => null,
            'selectedSerialIds' => [],
        ]);
    }

    public function edit(StockTransfer $transfer): View|RedirectResponse
    {
        $this->authorize('update', $transfer);

        if ($transfer->status !== 'draft') {
            return redirect()
                ->route('transfers.show', $transfer)
                ->with('status', 'Only draft transfers can be edited.');
        }

        $transfer->load('transferSerials');

        return view('transfers.create', [
            'locations' => Location::where('is_active', true)->orderBy('name')->get(),
            'transfer' => $transfer,
            'selectedSerialIds' => $transfer->transferSerials
                ->pluck('serial_item_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
        ]);
    }

    public function availableSerials(Request $request): JsonResponse
    {
        $this->authorize('create', StockTransfer::class);

        $data = $request->validate([
            'source_location_id' => ['required', 'exists:locations,id'],
            'product_id' => ['nullable', 'exists:products,id'],
            'query' => ['nullable', 'string', 'max:100'],
            'transfer_id' => ['nullable', 'integer', 'exists:stock_transfers,id'],
        ]);

        $editingTransferId = $this->resolveEditingTransferId($data['transfer_id'] ?? null);
        if ($editingTransferId === -1) {
            return response()->json(['data' => []]);
        }

        $serials = $this->availableSerialItemsQuery((int) $data['source_location_id'], $editingTransferId)
            ->when(! empty($data['product_id']), function ($query) use ($data) {
                $query->where('product_id', $data['product_id']);
            })
            ->when(! empty($data['query']), function ($query) use ($data) {
                $keyword = trim((string) $data['query']);
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('serial_number', 'like', "%{$keyword}%")
                        ->orWhere('manufacturer_serial', 'like', "%{$keyword}%")
                        ->orWhereHas('product', function ($productQuery) use ($keyword): void {
                            $productQuery->where('sku', 'like', "%{$keyword}%")
                                ->orWhere('name', 'like', "%{$keyword}%");
                        });

                    $labelCode = SerialLabelCode::parseScanCode($keyword);
                    if ($labelCode !== null) {
                        $subQuery->orWhere('label_code', $labelCode);
                    }
                });
            })
            ->orderBy('product_id')
            ->orderBy('serial_number')
            ->limit(400)
            ->get()
            ->map(fn (SerialItem $serial) => $this->formatAvailableSerial($serial))
            ->values();

        return response()->json(['data' => $serials]);
    }

    public function resolveSerialScan(Request $request): JsonResponse
    {
        $this->authorize('create', StockTransfer::class);

        $data = $request->validate([
            'source_location_id' => ['required', 'exists:locations,id'],
            'code' => ['required', 'string', 'max:120'],
            'transfer_id' => ['nullable', 'integer', 'exists:stock_transfers,id'],
        ]);

        $editingTransferId = $this->resolveEditingTransferId($data['transfer_id'] ?? null);
        if ($editingTransferId === -1) {
            return response()->json([
                'data' => null,
                'error' => 'This transfer can no longer be edited.',
            ]);
        }

        $code = trim((string) $data['code']);
        if ($code === '') {
            return response()->json(['data' => null]);
        }

        $labelCode = SerialLabelCode::parseScanCode($code);

        $matches = $this->availableSerialItemsQuery((int) $data['source_location_id'], $editingTransferId)
            ->where(function ($query) use ($code, $labelCode): void {
                $query->where('serial_number', $code)
                    ->orWhere('manufacturer_serial', $code);

                if ($labelCode !== null) {
                    $query->orWhere('label_code', $labelCode);
                }
            })
            ->orderBy('id')
            ->limit(5)
            ->get();

        if ($matches->isEmpty()) {
            return response()->json([
                'data' => null,
                'error' => 'No available in-stock serial matched this scan at the source warehouse.',
            ]);
        }

        if ($matches->count() > 1) {
            return response()->json([
                'data' => null,
                'error' => 'Multiple serials matched this code. Pick the unit manually from the list.',
            ]);
        }

        return response()->json([
            'data' => $this->formatAvailableSerial($matches->first()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', StockTransfer::class);

        $data = $request->validate([
            'source_location_id' => ['required', 'exists:locations,id', 'different:destination_location_id'],
            'destination_location_id' => ['required', 'exists:locations,id'],
            'transfer_date' => ['required', 'date'],
            'reason' => ['nullable', 'string'],
            'serial_ids' => ['required', 'array', 'min:1'],
            'serial_ids.*' => ['required', 'integer', 'distinct', 'exists:serial_items,id'],
        ]);

        $serials = SerialItem::query()
            ->whereIn('id', $data['serial_ids'])
            ->where('current_location_id', $data['source_location_id'])
            ->where('status', 'in_stock')
            ->whereNotIn('id', function ($query): void {
                $query->select('serial_item_id')
                    ->from('stock_transfer_serials')
                    ->whereIn('status', ['planned', 'issued']);
            })
            ->get();

        if ($serials->count() !== count($data['serial_ids'])) {
            throw ValidationException::withMessages([
                'serial_ids' => 'One or more selected serials are not available in the source warehouse.',
            ]);
        }

        DB::transaction(function () use ($data, $serials): void {
            $transfer = StockTransfer::create([
                'transfer_number' => $this->documentNumbers->next(
                    DocumentNumberGenerator::TYPE_STOCK_TRANSFER,
                    'TRF',
                    Carbon::parse((string) $data['transfer_date']),
                    'Ymd',
                    5,
                ),
                'source_location_id' => $data['source_location_id'],
                'destination_location_id' => $data['destination_location_id'],
                'reason' => $data['reason'],
                'requested_by' => auth()->id(),
                'requested_at' => Carbon::parse((string) $data['transfer_date'])->startOfDay(),
            ]);

            $qtyByProduct = $serials->groupBy('product_id')->map->count();
            foreach ($qtyByProduct as $productId => $qty) {
                StockTransferLine::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $productId,
                    'qty_requested' => $qty,
                    'qty_issued' => 0,
                    'qty_received' => 0,
                ]);
            }

            foreach ($serials as $serial) {
                StockTransferSerial::create([
                    'stock_transfer_id' => $transfer->id,
                    'serial_item_id' => $serial->id,
                    'product_id' => $serial->product_id,
                    'status' => 'planned',
                ]);
            }
        });

        return redirect()->route('transfers.index')->with('status', 'Transfer created with selected serials.');
    }

    public function update(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('update', $transfer);

        if ($transfer->status !== 'draft') {
            return redirect()
                ->route('transfers.show', $transfer)
                ->with('status', 'Only draft transfers can be edited.');
        }

        $data = $request->validate([
            'source_location_id' => ['required', 'exists:locations,id', 'different:destination_location_id'],
            'destination_location_id' => ['required', 'exists:locations,id'],
            'transfer_date' => ['required', 'date'],
            'reason' => ['nullable', 'string'],
            'serial_ids' => ['required', 'array', 'min:1'],
            'serial_ids.*' => ['required', 'integer', 'distinct', 'exists:serial_items,id'],
        ]);

        $serials = SerialItem::query()
            ->whereIn('id', $data['serial_ids'])
            ->where('current_location_id', $data['source_location_id'])
            ->where('status', 'in_stock')
            ->whereNotIn('id', function ($query) use ($transfer): void {
                $query->select('serial_item_id')
                    ->from('stock_transfer_serials')
                    ->whereIn('status', ['planned', 'issued'])
                    ->where('stock_transfer_id', '!=', $transfer->id);
            })
            ->get();

        if ($serials->count() !== count($data['serial_ids'])) {
            throw ValidationException::withMessages([
                'serial_ids' => 'One or more selected serials are not available in the source warehouse.',
            ]);
        }

        DB::transaction(function () use ($transfer, $data, $serials): void {
            $locked = StockTransfer::query()
                ->lockForUpdate()
                ->with('transferSerials')
                ->findOrFail($transfer->id);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages([
                    'serial_ids' => 'Transfer can no longer be edited because its status changed.',
                ]);
            }

            $locked->update([
                'source_location_id' => $data['source_location_id'],
                'destination_location_id' => $data['destination_location_id'],
                'reason' => $data['reason'],
                'requested_at' => Carbon::parse((string) $data['transfer_date'])->startOfDay(),
            ]);

            StockTransferSerial::query()->where('stock_transfer_id', $locked->id)->delete();
            StockTransferLine::query()->where('stock_transfer_id', $locked->id)->delete();

            $qtyByProduct = $serials->groupBy('product_id')->map->count();
            foreach ($qtyByProduct as $productId => $qty) {
                StockTransferLine::create([
                    'stock_transfer_id' => $locked->id,
                    'product_id' => $productId,
                    'qty_requested' => $qty,
                    'qty_issued' => 0,
                    'qty_received' => 0,
                ]);
            }

            foreach ($serials as $serial) {
                StockTransferSerial::create([
                    'stock_transfer_id' => $locked->id,
                    'serial_item_id' => $serial->id,
                    'product_id' => $serial->product_id,
                    'status' => 'planned',
                ]);
            }
        });

        return redirect()->route('transfers.show', $transfer)->with('status', 'Transfer updated.');
    }

    public function approveSource(StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('approveSource', $transfer);

        $before = $this->snapshotTransfer($transfer);

        try {
            $transfer = $this->approvalService->approveSource($transfer, auth()->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $this->recordTransferAudit('transfer.source_approved', $transfer, $before);

        if ($transfer->status === 'approved') {
            return back()->with('status', 'Sending and receiving branches approved this transfer. You can issue or receive directly.');
        }

        return back()->with('status', 'Sending branch approved. Waiting for receiving branch manager approval.');
    }

    public function approveDestination(StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('approveDestination', $transfer);

        $before = $this->snapshotTransfer($transfer);

        try {
            $transfer = $this->approvalService->approveDestination($transfer, auth()->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $this->recordTransferAudit('transfer.destination_approved', $transfer, $before);

        if ($transfer->status === 'approved') {
            return back()->with('status', 'Sending and receiving branches approved this transfer. You can issue or receive directly.');
        }

        return back()->with('status', 'Receiving branch approved. Waiting for sending branch manager approval.');
    }

    public function receiveDirect(
        StockTransfer $transfer,
        TransferIssueService $issueService,
        TransferReceiveService $receiveService
    ): RedirectResponse {
        $this->authorize('issue', $transfer);
        $this->authorize('receive', $transfer);

        if ($transfer->status !== 'approved') {
            return back()->with('status', 'Transfer must be approved before direct receive.');
        }

        $before = $this->snapshotTransfer($transfer);

        try {
            DB::transaction(function () use ($transfer, $issueService, $receiveService): void {
                $issueService->issue($transfer, auth()->id());

                $transfer->refresh();
                $receiveService->receive($transfer, auth()->id());
            });
        } catch (Throwable $e) {
            report($e);

            return back()->with('status', 'Direct receive failed. Please try issue then receive.');
        }

        $transfer->refresh();
        $this->recordTransferAudit('transfer.received_directly', $transfer, $before);

        return back()->with('status', 'Transfer received directly.');
    }

    public function issue(StockTransfer $transfer, TransferIssueService $service): RedirectResponse
    {
        $this->authorize('issue', $transfer);

        $before = $this->snapshotTransfer($transfer);
        $service->issue($transfer, auth()->id());
        $transfer->refresh();
        $this->recordTransferAudit('transfer.issued', $transfer, $before);

        return back()->with('status', 'Transfer issued.');
    }

    public function receive(StockTransfer $transfer, TransferReceiveService $service): RedirectResponse
    {
        $this->authorize('receive', $transfer);

        $before = $this->snapshotTransfer($transfer);
        $service->receive($transfer, auth()->id());
        $transfer->refresh();
        $this->recordTransferAudit('transfer.received', $transfer, $before);

        return back()->with('status', 'Transfer received.');
    }

    public function cancel(StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('cancel', $transfer);

        if (! in_array($transfer->status, ['draft', 'approved'], true)) {
            return redirect()
                ->route('transfers.show', $transfer)
                ->with('status', 'Only draft or approved transfers can be cancelled.');
        }

        $before = $this->snapshotTransfer($transfer);

        DB::transaction(function () use ($transfer): void {
            StockTransferSerial::query()
                ->where('stock_transfer_id', $transfer->id)
                ->where('status', 'planned')
                ->update(['status' => 'cancelled']);

            $transfer->update(['status' => 'cancelled']);
        });

        $transfer->refresh();
        $this->recordTransferAudit('transfer.cancelled', $transfer, $before);

        return redirect()
            ->route('transfers.show', $transfer)
            ->with('status', 'Transfer cancelled.');
    }

    public function destroy(StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('delete', $transfer);

        try {
            DB::transaction(function () use ($transfer): void {
                $locked = StockTransfer::query()
                    ->lockForUpdate()
                    ->with(['lines', 'transferSerials.serialItem'])
                    ->findOrFail($transfer->id);

                if (in_array($locked->status, ['draft', 'cancelled'], true)) {
                    $this->stockTransferLedgerQuery($locked->id)->delete();

                    StockTransferSerial::query()->where('stock_transfer_id', $locked->id)->delete();
                    StockTransferLine::query()->where('stock_transfer_id', $locked->id)->delete();
                    $locked->delete();

                    return;
                }

                $sourceLocationId = (int) $locked->source_location_id;
                $destinationLocationId = (int) $locked->destination_location_id;
                $touchedProducts = [];
                foreach ($locked->transferSerials as $row) {
                    $serial = $row->serialItem()->lockForUpdate()->firstOrFail();
                    $productId = (int) $serial->product_id;
                    $touchedProducts[$productId] = true;

                    $serial->update([
                        'status' => 'in_stock',
                        'current_location_id' => $sourceLocationId,
                    ]);
                }

                $this->stockTransferLedgerQuery($locked->id)->delete();

                StockTransferSerial::query()->where('stock_transfer_id', $locked->id)->delete();
                StockTransferLine::query()->where('stock_transfer_id', $locked->id)->delete();
                $locked->delete();

                $inventoryBalanceService = app(InventoryBalanceService::class);
                foreach (array_keys($touchedProducts) as $productId) {
                    $inventoryBalanceService->recalculate((int) $productId, $sourceLocationId);
                    $inventoryBalanceService->recalculate((int) $productId, $destinationLocationId);
                }
            });
        } catch (Throwable $e) {
            return redirect()
                ->route('transfers.show', $transfer)
                ->with('swal', [
                    'icon' => 'error',
                    'title' => 'Delete failed',
                    'text' => $e->getMessage() !== '' ? $e->getMessage() : 'Unable to delete transfer.',
                ]);
        }

        return redirect()->route('transfers.index')->with('status', 'Transfer deleted. Stock quantities and serial locations were reverted.');
    }

    /**
     * stock_ledger rows use {@see StockTransfer::LEDGER_REFERENCE_TYPE}; older code may still have the FQCN.
     *
     * @return Builder<StockLedger>
     */
    private function stockTransferLedgerQuery(int $transferId): Builder
    {
        return StockLedger::query()
            ->where('reference_id', $transferId)
            ->where(function ($q): void {
                $q->where('reference_type', StockTransfer::LEDGER_REFERENCE_TYPE)
                    ->orWhere('reference_type', StockTransfer::class);
            });
    }

    private function snapshotTransfer(StockTransfer $transfer): array
    {
        return [
            'status' => $transfer->status,
            'source_approved_at' => optional($transfer->source_approved_at)?->toDateTimeString(),
            'destination_approved_at' => optional($transfer->destination_approved_at)?->toDateTimeString(),
            'approved_at' => optional($transfer->approved_at)?->toDateTimeString(),
            'issued_at' => optional($transfer->issued_at)?->toDateTimeString(),
            'received_at' => optional($transfer->received_at)?->toDateTimeString(),
            'source_approved_by' => $transfer->source_approved_by,
            'destination_approved_by' => $transfer->destination_approved_by,
            'approved_by' => $transfer->approved_by,
            'issued_by' => $transfer->issued_by,
            'received_by' => $transfer->received_by,
        ];
    }

    private function recordTransferAudit(string $action, StockTransfer $transfer, array $beforeSnapshot): void
    {
        AuditLog::create([
            'actor_user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => 'StockTransfer',
            'entity_id' => $transfer->id,
            'before_snapshot' => $beforeSnapshot,
            'after_snapshot' => $this->snapshotTransfer($transfer),
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function companyPadSettings(): array
    {
        $keys = [
            'company_pad_name',
            'company_pad_logo_path',
            'company_pad_tagline',
            'company_pad_address',
            'company_pad_phone',
            'company_pad_email',
            'company_pad_website',
            'company_pad_header_note',
            'company_pad_footer_note',
            'company_pad_footer_left_label',
            'company_pad_footer_right_label',
        ];

        $stored = AppSetting::getMany($keys);

        $defaults = [
            'company_pad_name' => (string) config('app.name'),
            'company_pad_logo_path' => '',
            'company_pad_tagline' => 'Sales & Inventory Management',
            'company_pad_address' => '',
            'company_pad_phone' => '',
            'company_pad_email' => '',
            'company_pad_website' => '',
            'company_pad_header_note' => '',
            'company_pad_footer_note' => 'Thank you for your business.',
            'company_pad_footer_left_label' => 'Shipped / issued by',
            'company_pad_footer_right_label' => 'Received by',
        ];

        foreach ($defaults as $key => $value) {
            $stored[$key] = ($stored[$key] !== null && $stored[$key] !== '') ? (string) $stored[$key] : $value;
        }

        /** @var array<string, string> $stored */
        return $stored;
    }

    /**
     * @return Builder<SerialItem>
     */
    private function availableSerialItemsQuery(int $sourceLocationId, int $editingTransferId): Builder
    {
        return SerialItem::query()
            ->where('current_location_id', $sourceLocationId)
            ->where('status', 'in_stock')
            ->whereNotIn('id', function ($sub) use ($editingTransferId): void {
                if ($editingTransferId > 0) {
                    $sub->select('serial_item_id')
                        ->from('stock_transfer_serials')
                        ->whereIn('status', ['planned', 'issued'])
                        ->where('stock_transfer_id', '!=', $editingTransferId);

                    return;
                }

                $sub->select('serial_item_id')
                    ->from('stock_transfer_serials')
                    ->whereIn('status', ['planned', 'issued']);
            })
            ->with('product');
    }

    /**
     * @return array{id: int, serial_number: string, manufacturer_serial: ?string, serial_display: string, product_id: int, product_label: string}
     */
    private function formatAvailableSerial(SerialItem $serial): array
    {
        return [
            'id' => $serial->id,
            'serial_number' => $serial->serial_number,
            'manufacturer_serial' => $serial->manufacturer_serial,
            'serial_display' => $serial->serial_number.' (MFG: '.($serial->manufacturer_serial ?: 'N/A').')',
            'product_id' => $serial->product_id,
            'product_label' => ($serial->product?->sku ?? '').' - '.($serial->product?->name ?? ''),
        ];
    }

    /**
     * Returns 0 when not editing, transfer id when editing draft, -1 when editing non-draft.
     */
    private function resolveEditingTransferId(mixed $transferId): int
    {
        $editingTransferId = (int) ($transferId ?? 0);
        if ($editingTransferId <= 0) {
            return 0;
        }

        $editingTransfer = StockTransfer::query()->findOrFail($editingTransferId);
        $this->authorize('update', $editingTransfer);
        if ($editingTransfer->status !== 'draft') {
            return -1;
        }

        return $editingTransferId;
    }
}
