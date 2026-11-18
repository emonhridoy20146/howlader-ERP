<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Exceptions\InvalidStateException;
use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\ReturnLine;
use App\Models\ReturnOrder;
use App\Models\SalesInvoice;
use App\Models\Wallet;
use App\Services\DocumentNumberGenerator;
use App\Services\ReturnResolutionService;
use App\Services\ReturnRollbackService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReturnController extends Controller
{
    use AppliesInventoryLocationContext;

    public function __construct(
        private readonly DocumentNumberGenerator $documentNumbers,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'status' => (string) $request->input('status', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
            'invoice_q' => trim((string) $request->input('invoice_q', '')),
            'customer_q' => trim((string) $request->input('customer_q', '')),
        ];

        $returns = ReturnOrder::query()
            ->with(['customer', 'branch', 'salesInvoice', 'refund'])
            ->when($this->contextLocationId() !== null, fn ($query) => $query->where('branch_id', $this->contextLocationId()))
            ->when($filters['q'] !== '', fn ($query) => $query->where('return_number', 'like', "%{$filters['q']}%"))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('return_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('return_date', '<=', $filters['date_to']))
            ->when($filters['invoice_q'] !== '', function ($query) use ($filters) {
                $term = '%'.addcslashes($filters['invoice_q'], '%_\\').'%';
                $query->whereHas('salesInvoice', fn ($q) => $q->where('invoice_number', 'like', $term));
            })
            ->when($filters['customer_q'] !== '', function ($query) use ($filters) {
                $term = '%'.addcslashes($filters['customer_q'], '%_\\').'%';
                $query->whereHas('customer', fn ($q) => $q->where('name', 'like', $term));
            })
            ->latest()
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $kpiScope = ReturnOrder::query()
            ->when($this->contextLocationId() !== null, fn ($query) => $query->where('branch_id', $this->contextLocationId()));

        $kpis = [
            'total' => (clone $kpiScope)->count(),
            'draft' => (clone $kpiScope)->where('status', 'draft')->count(),
            'approved' => (clone $kpiScope)->where('status', 'approved')->count(),
            'restocked' => (clone $kpiScope)->where('status', 'restocked')->count(),
            'filtered' => $returns->total(),
        ];

        $locationIds = $this->accessibleLocationIds();
        $locations = Location::query()
            ->where('is_active', true)
            ->when($locationIds !== [], fn ($q) => $q->whereIn('id', $locationIds))
            ->orderBy('name')
            ->get();

        $customers = Customer::query()->where('is_active', true)->orderBy('name')->get();

        $prefillReturn = null;
        $open = $request->query('open');
        if (is_string($open) && ctype_digit($open)) {
            $candidate = ReturnOrder::query()->where('status', 'draft')->find((int) $open);
            if ($candidate && Gate::allows('update', $candidate)) {
                $prefillReturn = $candidate;
            }
        }

        $returnSheetBoot = [
            'openCreate' => false,
            'editForm' => null,
        ];

        if ($prefillReturn) {
            $returnSheetBoot['editForm'] = [
                'id' => $prefillReturn->id,
                'sales_invoice_id' => (int) $prefillReturn->sales_invoice_id,
                'customer_id' => (int) $prefillReturn->customer_id,
                'branch_id' => (int) $prefillReturn->branch_id,
                'return_date' => $prefillReturn->return_date->toDateString(),
                'reason' => (string) ($prefillReturn->reason ?? ''),
            ];
        } elseif ($request->old('return_order_id')) {
            $returnSheetBoot['editForm'] = [
                'id' => (int) $request->old('return_order_id'),
                'sales_invoice_id' => (int) $request->old('sales_invoice_id'),
                'customer_id' => (int) $request->old('customer_id'),
                'branch_id' => (int) $request->old('branch_id'),
                'return_date' => (string) $request->old('return_date'),
                'reason' => (string) $request->old('reason', ''),
            ];
        }

        $createOldBoot = null;
        if ($request->session()->has('errors') && ! $request->old('return_order_id')) {
            $createOldBoot = [
                'sales_invoice_id' => (string) $request->old('sales_invoice_id', ''),
                'customer_id' => (string) $request->old('customer_id', ''),
                'branch_id' => (string) $request->old('branch_id', ''),
                'return_date' => (string) $request->old('return_date', ''),
                'reason' => (string) $request->old('reason', ''),
            ];
        }

        if ($createOldBoot === null && $returnSheetBoot['editForm'] === null && $request->filled('sales_invoice_id')) {
            $sid = (int) $request->query('sales_invoice_id');
            if ($sid > 0) {
                $inv = $this->salesInvoiceForReturnPickById($sid);
                if ($inv !== null) {
                    $createOldBoot = [
                        'sales_invoice_id' => (string) $inv->id,
                        'customer_id' => (string) $inv->customer_id,
                        'branch_id' => (string) $inv->branch_id,
                        'return_date' => now()->toDateString(),
                        'reason' => '',
                        'customer_search' => (string) ($inv->customer?->name ?? ''),
                        'invoice_search' => (string) $inv->invoice_number,
                    ];
                }
            }
        }

        $returnSheetBoot['openCreate'] = $returnSheetBoot['editForm'] === null && (
            $request->query('open') === 'create'
            || ($request->session()->has('errors') && ! $request->old('return_order_id'))
            || $createOldBoot !== null
        );

        return view('returns.index', compact(
            'returns',
            'filters',
            'kpis',
            'locations',
            'customers',
            'returnSheetBoot',
            'createOldBoot',
        ));
    }

    public function show(ReturnOrder $return, ReturnResolutionService $service): View
    {
        $return = $this->returnInContext($return);
        $this->authorize('view', $return);

        $return->load([
            'customer',
            'branch',
            'returnLines.salesInvoiceLine.product',
            'returnLines.salesInvoiceLine.saleLineSerials.serialItem',
            'returnLines.serialItem',
            'salesInvoice.lines' => fn ($q) => $q->orderBy('id')->with([
                'product:id,name,model_number,is_serialized',
                'saleLineSerials.serialItem:id,serial_number,manufacturer_serial',
            ]),
            'salesInvoice.customer:id,name',
            'salesInvoice.branch:id,code,name',
            'refund.allocations.payment.wallet',
        ]);

        $wallets = Wallet::query()
            ->whereAppliesToLocation($return->branch_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'kind']);

        $refundLineValueBase = $service->refundBaseForReturnOrder($return);
        $refundSettlementBase = $service->refundSettlementBaseForReturnOrder($return);
        $refundableCashRemaining = $return->salesInvoice
            ? $service->refundablePaidCashRemaining($return->salesInvoice, $return->id)
            : 0.0;

        return view('returns.show', compact('return', 'wallets', 'refundLineValueBase', 'refundSettlementBase', 'refundableCashRemaining'));
    }

    public function receipt(ReturnOrder $return): View
    {
        $return = $this->returnInContext($return);
        $this->authorize('view', $return);

        $return->load([
            'customer',
            'branch',
            'salesInvoice',
            'returnLines.salesInvoiceLine.product',
            'returnLines.serialItem',
            'refund.allocations.payment',
        ]);

        return view('returns.receipt', [
            'return' => $return,
            'printedAt' => now(),
            'companyPad' => $this->companyPadSettings(),
        ]);
    }

    public function lookupCustomers(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ReturnOrder::class);

        $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $q = trim((string) $request->input('q', ''));
        $term = $q !== '' ? '%'.addcslashes($q, '%_\\').'%' : null;

        $rows = Customer::query()
            ->where('is_active', true)
            ->when($term !== null, function ($query) use ($term): void {
                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('customer_code', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->orderBy('name')
            ->limit(40)
            ->get(['id', 'name', 'customer_code', 'phone']);

        return response()->json(['customers' => $rows]);
    }

    public function lookupInvoices(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ReturnOrder::class);

        $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'invoice_id' => ['nullable', 'integer', 'exists:sales_invoices,id'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        if ($request->filled('invoice_id')) {
            $invoice = $this->salesInvoiceForReturnPickById((int) $request->input('invoice_id'));

            return response()->json([
                'invoices' => $invoice ? [$this->returnInvoiceToArray($invoice)] : [],
            ]);
        }

        $customerId = $request->filled('customer_id') ? (int) $request->input('customer_id') : null;
        $search = trim((string) $request->input('q', ''));
        $like = $search !== '' ? '%'.addcslashes($search, '%_\\').'%' : null;

        $query = $this->eligibleSalesInvoicesBaseQuery()
            ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))
            ->when($like !== null, function ($q) use ($like): void {
                $q->where(function ($inner) use ($like): void {
                    $inner->where('invoice_number', 'like', $like)
                        ->orWhereHas('lines.product', function ($pq) use ($like): void {
                            $pq->where('name', 'like', $like)
                                ->orWhere('model_number', 'like', $like);
                        })
                        ->orWhereHas('lines.saleLineSerials.serialItem', function ($sq) use ($like): void {
                            $sq->where('serial_number', 'like', $like)
                                ->orWhere('manufacturer_serial', 'like', $like);
                        });
                });
            })
            ->latest('invoice_date')
            ->limit(40);

        $invoices = $query->get()->map(fn (SalesInvoice $inv) => $this->returnInvoiceToArray($inv));

        return response()->json(['invoices' => $invoices]);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('returns.index', ['open' => 'create']);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ReturnOrder::class);

        $return = DB::transaction(function () use ($request): ReturnOrder {
            $data = $this->validatedReturnPayload($request);
            $return = ReturnOrder::create([
                ...$data,
                'return_number' => $this->nextReturnNumber((string) $data['return_date']),
                'created_by' => auth()->id(),
            ]);
            $this->seedDefaultReturnLines($return->fresh([
                'salesInvoice.lines.product',
                'salesInvoice.lines.saleLineSerials',
            ]));

            return $return;
        });

        return redirect()->route('returns.show', $return)->with('status', 'Return order created.');
    }

    public function update(Request $request, ReturnOrder $return): RedirectResponse
    {
        $return = $this->returnInContext($return);
        $this->authorize('update', $return);

        $originalInvoiceId = (int) $return->sales_invoice_id;

        DB::transaction(function () use ($request, $return, $originalInvoiceId): void {
            $data = $this->validatedReturnPayload($request);
            $return->update($data);
            if ((int) $return->sales_invoice_id !== $originalInvoiceId) {
                $return->returnLines()->delete();
                $this->seedDefaultReturnLines($return->fresh([
                    'salesInvoice.lines.product',
                    'salesInvoice.lines.saleLineSerials',
                ]));
            }
        });

        return redirect()->route('returns.show', $return)->with('status', 'Return order updated.');
    }

    public function updateItems(Request $request, ReturnOrder $return): RedirectResponse
    {
        $return = $this->returnInContext($return);
        $this->authorize('update', $return);

        if ($return->status !== 'draft') {
            return redirect()
                ->route('returns.show', $return)
                ->withErrors(['lines' => 'Only draft returns can change returned items.']);
        }

        $this->validateAndSyncReturnItems($request, $return);

        return redirect()->route('returns.show', $return)->with('status', 'Return items updated.');
    }

    public function approve(ReturnOrder $return): RedirectResponse
    {
        $this->authorize('approve', $return);

        $return->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return back()->with('status', 'Return approved.');
    }

    public function resolve(Request $request, ReturnOrder $return, ReturnResolutionService $service): RedirectResponse
    {
        $return = $this->returnInContext($return);
        $this->authorize('resolve', $return);

        $request->merge([
            'refund_amount_mode' => $request->input('refund_amount_mode', 'percent'),
        ]);

        $data = $request->validate([
            'settlement_mode' => ['required', Rule::in(['full', 'partial_deferred', 'partial_final'])],
            'refund_amount_mode' => ['required', Rule::in(['percent', 'flat'])],
            'refund_percentage' => ['required_if:refund_amount_mode,percent', 'nullable', 'numeric', 'min:0', 'max:100'],
            'refund_flat_amount' => ['required_if:refund_amount_mode,flat', 'nullable', 'numeric', 'min:0'],
            'pay_now_amount' => ['required', 'numeric', 'min:0'],
            'wallet_id' => ['nullable', 'integer', 'exists:wallets,id'],
            'payment_date' => ['nullable', 'date'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'resolution_notes' => ['nullable', 'string'],
        ]);

        if (($data['refund_amount_mode'] ?? 'percent') === 'flat') {
            $settlementBase = $service->refundSettlementBaseForReturnOrder($return);
            $flatAmount = round((float) ($data['refund_flat_amount'] ?? 0), 2);
            if ($flatAmount > $settlementBase + 0.001) {
                return back()->withErrors([
                    'refund_flat_amount' => 'Refund amount cannot exceed the refundable paid share ('.number_format($settlementBase, 2).').',
                ])->withInput();
            }
        }

        $settlementBase = $service->refundSettlementBaseForReturnOrder($return);
        $settlementPreview = $service->calculateSettlementAmount($return, $data);
        if ($settlementPreview > $settlementBase + 0.001) {
            return back()->withErrors([
                'refund_percentage' => 'Settlement cannot exceed the refundable paid share ('.number_format($settlementBase, 2).').',
                'refund_flat_amount' => 'Settlement cannot exceed the refundable paid share ('.number_format($settlementBase, 2).').',
            ])->withInput();
        }

        $return->loadMissing('salesInvoice');
        $payNowPreview = round((float) $data['pay_now_amount'], 2);
        if ($return->salesInvoice && $payNowPreview > 0) {
            $cashRemaining = $service->refundablePaidCashRemaining($return->salesInvoice, $return->id);
            $maxPayNow = min($settlementPreview, $cashRemaining);
            if ($payNowPreview > $maxPayNow + 0.001) {
                return back()->withErrors([
                    'pay_now_amount' => 'Pay now cannot exceed the remaining paid amount on this invoice ('.number_format($maxPayNow, 2).' available).',
                ])->withInput();
            }
        }

        if ($payNowPreview > $settlementPreview + 0.001) {
            return back()->withErrors([
                'pay_now_amount' => 'Pay now cannot exceed the settlement amount ('.number_format($settlementPreview, 2).').',
            ])->withInput();
        }

        if ((float) $data['pay_now_amount'] > 0) {
            if (empty($data['wallet_id'])) {
                return back()->withErrors(['wallet_id' => 'Wallet is required when paying now.'])->withInput();
            }
            $wallet = Wallet::query()
                ->whereAppliesToLocation($return->branch_id)
                ->where('is_active', true)
                ->find($data['wallet_id']);
            if (! $wallet) {
                return back()->withErrors(['wallet_id' => 'Selected wallet is not active or not available for this branch.'])->withInput();
            }
        }
        // A reason is only needed when money is actually being settled; a zero refund needs none.
        if ($data['settlement_mode'] === 'partial_final' && $settlementPreview > 0.009 && blank($data['resolution_notes'] ?? null)) {
            return back()->withErrors(['resolution_notes' => 'Please provide a reason for final partial settlement.'])->withInput();
        }

        if (in_array($data['settlement_mode'], ['full', 'partial_final'], true)) {
            if ($settlementPreview > 0.009 && empty($data['wallet_id'])) {
                return back()->withErrors(['wallet_id' => 'Wallet is required to pay the settlement amount.'])->withInput();
            }
        }

        try {
            $service->resolve($return, $data, (int) auth()->id());
        } catch (InvalidStateException $e) {
            // e.g. no invoice lines selected yet, or the return is no longer a draft: show it, don't 500.
            return redirect()
                ->route('returns.show', $return)
                ->withErrors(['resolve' => $e->getMessage()]);
        }

        return redirect()
            ->route('returns.show', $return)
            ->with('status', 'Return resolved successfully.');
    }

    public function payRefund(
        Request $request,
        ReturnOrder $return,
        ReturnResolutionService $service
    ): RedirectResponse {
        $return = $this->returnInContext($return);
        $this->authorize('payRefund', $return);

        $data = $request->validate([
            'pay_amount' => ['required', 'numeric', 'gt:0'],
            'wallet_id' => ['required', 'integer', 'exists:wallets,id'],
            'payment_date' => ['nullable', 'date'],
            'reference_no' => ['nullable', 'string', 'max:100'],
        ]);

        $wallet = Wallet::query()
            ->whereAppliesToLocation($return->branch_id)
            ->where('is_active', true)
            ->find($data['wallet_id']);
        if (! $wallet) {
            return back()->withErrors(['wallet_id' => 'Selected wallet is not active or not available for this branch.'])->withInput();
        }

        $return->loadMissing('salesInvoice');
        if ($return->salesInvoice) {
            $maxPay = min(
                $return->refundAmountPayable(),
                $service->refundablePaidCashRemaining($return->salesInvoice, $return->id),
            );
            if ((float) $data['pay_amount'] > $maxPay + 0.001) {
                return back()->withErrors([
                    'pay_amount' => 'Payment cannot exceed the remaining paid amount on this invoice ('.number_format($maxPay, 2).' available).',
                ])->withInput();
            }
        }

        $service->payPending($return, $data, (int) auth()->id());

        return redirect()
            ->route('returns.show', $return)
            ->with('status', 'Pending refund payment recorded.');
    }

    public function rollback(
        Request $request,
        ReturnOrder $return,
        ReturnRollbackService $service,
    ): RedirectResponse {
        $return = $this->returnInContext($return);
        $this->authorize('rollback', $return);

        $data = $request->validate([
            'rollback_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $service->rollback($return, $data['rollback_notes'] ?? null, (int) auth()->id());
        } catch (InvalidStateException $e) {
            return redirect()
                ->route('returns.show', $return)
                ->withErrors(['rollback' => $e->getMessage()]);
        }

        return redirect()
            ->route('returns.show', $return)
            ->with('status', 'Return rolled back. Items are marked sold again and refund wallet entries were reversed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function returnInvoiceToArray(SalesInvoice $inv): array
    {
        $linesOut = [];
        foreach ($inv->lines as $line) {
            $serials = $line->saleLineSerials
                ->map(fn ($sl) => $sl->serialItem?->serial_number ?: $sl->serialItem?->manufacturer_serial)
                ->filter()
                ->values()
                ->all();
            $linesOut[] = [
                'qty' => (float) $line->qty,
                'product_name' => $line->product?->name,
                'model_number' => $line->product?->model_number,
                'line_total' => number_format((float) $line->line_total, 2, '.', ''),
                'serials' => array_slice($serials, 0, 12),
                'serials_more' => max(0, count($serials) - 12),
            ];
        }

        $subtitleParts = [];
        foreach ($inv->lines->take(4) as $line) {
            $p = $line->product;
            $serial = $line->saleLineSerials->first();
            $sn = $serial?->serialItem?->serial_number ?: $serial?->serialItem?->manufacturer_serial;
            $label = trim(($p?->name ?? 'Line').($p?->model_number ? ' · '.$p->model_number : ''));
            $label .= ' ×'.(string) $line->qty;
            if ($sn) {
                $label .= ' · SN '.$sn;
            }
            $subtitleParts[] = $label;
        }
        if ($inv->lines->count() > 4) {
            $subtitleParts[] = '+'.($inv->lines->count() - 4).' more lines';
        }

        return [
            'id' => $inv->id,
            'invoice_number' => $inv->invoice_number,
            'invoice_date' => $inv->invoice_date?->toDateString(),
            'customer_id' => $inv->customer_id,
            'branch_id' => $inv->branch_id,
            'customer_name' => $inv->customer?->name,
            'branch_code' => $inv->branch?->code,
            'grand_total' => number_format((float) $inv->grand_total, 2, '.', ''),
            'amount_paid' => number_format((float) $inv->amount_paid, 2, '.', ''),
            'amount_due' => number_format((float) $inv->amount_due, 2, '.', ''),
            'option_label' => $inv->invoice_number.' · '.($inv->customer?->name ?? '—').' · '.$inv->branch?->name.' · '.number_format((float) $inv->grand_total, 2).' · Paid '.number_format((float) $inv->amount_paid, 2),
            'option_subtitle' => implode(' | ', $subtitleParts) ?: 'No lines',
            'lines' => $linesOut,
        ];
    }

    private function eligibleSalesInvoicesBaseQuery(): Builder
    {
        $locationIds = $this->accessibleLocationIds();

        return SalesInvoice::query()
            ->whereIn('status', ['posted', 'active'])
            ->when($this->contextLocationId() !== null, fn ($q) => $q->where('branch_id', $this->contextLocationId()))
            ->when($this->contextLocationId() === null && $locationIds !== [], fn ($q) => $q->whereIn('branch_id', $locationIds))
            ->with([
                'customer:id,name',
                'branch:id,code,name',
                'lines' => fn ($q) => $q->orderBy('id')->with([
                    'product:id,name,model_number',
                    'saleLineSerials.serialItem:id,serial_number,manufacturer_serial',
                ]),
            ]);
    }

    /**
     * Resolve a single invoice by id for return creation / lookup. Uses any branch the user can access,
     * not only the pinned inventory context, so "Start return" from an invoice still works when context
     * is another location.
     */
    private function salesInvoiceForReturnPickById(int $id): ?SalesInvoice
    {
        $locationIds = $this->accessibleLocationIds();

        $invoice = SalesInvoice::query()
            ->whereKey($id)
            ->whereIn('status', ['posted', 'active'])
            ->when($locationIds !== [], fn ($q) => $q->whereIn('branch_id', $locationIds))
            ->with([
                'customer:id,name',
                'branch:id,code,name',
                'lines' => fn ($q) => $q->orderBy('id')->with([
                    'product:id,name,model_number',
                    'saleLineSerials.serialItem:id,serial_number,manufacturer_serial',
                ]),
            ])
            ->first();

        if ($invoice === null || ! Gate::allows('view', $invoice)) {
            return null;
        }

        return $invoice;
    }

    /**
     * @return array{sales_invoice_id: int, customer_id: int, branch_id: int, return_date: string, reason: ?string}
     */
    private function validatedReturnPayload(Request $request): array
    {
        $locationIds = $this->accessibleLocationIds();

        $branchRules = ['required', 'integer', 'exists:locations,id'];
        if ($locationIds !== []) {
            $branchRules[] = Rule::in($locationIds);
        }

        return $request->validate([
            'sales_invoice_id' => [
                'required',
                'integer',
                Rule::exists('sales_invoices', 'id')
                    ->whereIn('status', ['posted', 'active'])
                    ->where(fn ($q) => $q
                        ->where('customer_id', (int) $request->input('customer_id'))
                        ->where('branch_id', (int) $request->input('branch_id'))),
            ],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'branch_id' => $branchRules,
            'return_date' => ['required', 'date'],
            'reason' => ['nullable', 'string'],
        ]);
    }

    private function nextReturnNumber(string $returnDate): string
    {
        return $this->documentNumbers->next(
            DocumentNumberGenerator::TYPE_RETURN_ORDER,
            'RET',
            Carbon::parse($returnDate),
            'Ym',
            5,
        );
    }

    private function returnInContext(ReturnOrder $return): ReturnOrder
    {
        return ReturnOrder::query()
            ->whereKey($return->id)
            ->tap(fn ($q) => $this->scopeContextBranch($q))
            ->firstOrFail();
    }

    private function seedDefaultReturnLines(ReturnOrder $return): void
    {
        $return->loadMissing('salesInvoice.lines.product', 'salesInvoice.lines.saleLineSerials');
        $invoice = $return->salesInvoice;
        if (! $invoice) {
            return;
        }

        $return->returnLines()->delete();

        foreach ($invoice->lines as $line) {
            $isSerialized = $line->isReturnedBySerial();
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
    }

    private function validateAndSyncReturnItems(Request $request, ReturnOrder $return): void
    {
        $return->load([
            'salesInvoice.lines.product',
            'salesInvoice.lines.saleLineSerials',
        ]);
        $invoice = $return->salesInvoice;
        if (! $invoice) {
            throw ValidationException::withMessages(['lines' => 'Return has no linked invoice.']);
        }

        $linesInput = $request->input('lines', []);
        $rows = [];

        foreach ($invoice->lines as $line) {
            $lineId = $line->id;
            $payload = $linesInput[$lineId] ?? $linesInput[(string) $lineId] ?? [];
            $isSerialized = $line->isReturnedBySerial();

            if ($isSerialized) {
                $selected = array_values(array_unique(array_map('intval', (array) ($payload['serials'] ?? []))));
                $allowed = $line->saleLineSerials->pluck('serial_item_id')->map(fn ($id) => (int) $id)->all();
                foreach ($selected as $serialId) {
                    if (! in_array($serialId, $allowed, true)) {
                        throw ValidationException::withMessages([
                            "lines.$lineId" => 'Invalid serial selection for this line.',
                        ]);
                    }
                    $rows[] = [
                        'sales_invoice_line_id' => $line->id,
                        'serial_item_id' => $serialId,
                        'qty' => null,
                    ];
                }
            } else {
                $qty = (int) ($payload['qty'] ?? 0);
                if ($qty < 0 || $qty > (int) $line->qty) {
                    throw ValidationException::withMessages([
                        "lines.$lineId" => 'Quantity must be between 0 and the invoiced quantity.',
                    ]);
                }
                if ($qty > 0) {
                    $rows[] = [
                        'sales_invoice_line_id' => $line->id,
                        'serial_item_id' => null,
                        'qty' => $qty,
                    ];
                }
            }
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'lines' => 'Select at least one serial or enter a quantity to return.',
            ]);
        }

        DB::transaction(function () use ($return, $rows): void {
            $return->returnLines()->delete();
            foreach ($rows as $row) {
                ReturnLine::create([
                    'return_order_id' => $return->id,
                    'sales_invoice_line_id' => $row['sales_invoice_line_id'],
                    'serial_item_id' => $row['serial_item_id'],
                    'qty' => $row['qty'],
                    'resolution' => 'restock',
                    'condition_notes' => null,
                ]);
            }
        });
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
            'company_pad_footer_left_label' => 'Customer signature',
            'company_pad_footer_right_label' => 'Store representative',
        ];

        foreach ($defaults as $key => $value) {
            $stored[$key] = ($stored[$key] !== null && $stored[$key] !== '') ? (string) $stored[$key] : $value;
        }

        /** @var array<string, string> $stored */
        return $stored;
    }
}
