<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidStateException;
use App\Exceptions\SerialCountMismatchException;
use App\Exceptions\SerialLocationMismatchException;
use App\Exceptions\SerialNotAvailableException;
use App\Exceptions\SerialSaleLedgerIntegrityException;
use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\AccountsReceivableLedger;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\InvoiceDebtWriteoff;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SaleLineSerial;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\ActiveInstallmentInvoiceRevisionService;
use App\Services\DebtWriteoffService;
use App\Services\DocumentNumberGenerator;
use App\Services\InstallmentAdvanceService;
use App\Services\InstallmentInvoiceSyncService;
use App\Services\InventoryBalanceService;
use App\Services\JournalService;
use App\Services\PaymentService;
use App\Services\PostedInvoiceSaleTypeChangeService;
use App\Services\SalesLineDiscountLimitService;
use App\Services\SalesInvoiceBranchChangeService;
use App\Services\SalesPostingService;
use App\Support\LedgerBackedInventory;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SalesInvoiceController extends Controller
{
    use AppliesInventoryLocationContext;

    public function __construct(
        private readonly DocumentNumberGenerator $documentNumbers,
        private readonly SalesLineDiscountLimitService $lineDiscountLimits,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'status' => (string) $request->input('status', ''),
            'payment_status' => (string) $request->input('payment_status', ''),
            'sale_type' => (string) $request->input('sale_type', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
        ];

        $baseQuery = SalesInvoice::query()
            ->with([
                'customer',
                'branch',
                'payments.paymentMethodRef',
            ])
            ->withSum('lines', 'line_total')
            ->withSum('lines', 'discount_amount')
            ->withSum('lines', 'service_charge')
            ->withMin('lines', 'unit_price')
            ->withMax('lines', 'unit_price')
            ->when($this->contextLocationId() !== null, fn ($query) => $query->where('branch_id', $this->contextLocationId()))
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('invoice_number', 'like', "%{$keyword}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($keyword): void {
                            $customerQuery->where('name', 'like', "%{$keyword}%")
                                ->orWhere('phone', 'like', "%{$keyword}%");
                        });
                });
            })
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['payment_status'] !== '', fn ($query) => $query->where('payment_status', $filters['payment_status']))
            ->when($filters['sale_type'] !== '', fn ($query) => $query->where('sale_type', $filters['sale_type']))
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('invoice_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('invoice_date', '<=', $filters['date_to']));

        $invoices = (clone $baseQuery)
            ->orderBy('invoice_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $kpiScope = SalesInvoice::query()
            ->when($this->contextLocationId() !== null, fn ($query) => $query->where('branch_id', $this->contextLocationId()));

        $kpis = [
            'total' => (clone $kpiScope)->count(),
            'posted' => (clone $kpiScope)->whereIn('status', ['posted', 'active'])->count(),
            'paid' => (clone $kpiScope)->where('payment_status', 'paid')->count(),
            'filtered' => $invoices->total(),
            'filtered_total_amount' => (float) (clone $baseQuery)->sum('grand_total'),
            'filtered_due_amount' => (float) (clone $baseQuery)->sum('amount_due'),
        ];

        $paymentMethodsByBranch = [];
        foreach ($invoices->getCollection()->pluck('branch_id')->unique()->filter() as $branchId) {
            $paymentMethodsByBranch[(int) $branchId] = $this->availablePaymentMethodsForLocation((int) $branchId);
        }

        return view('sales.invoices.index', compact('invoices', 'filters', 'kpis', 'paymentMethodsByBranch'));
    }

    public function create(): View
    {
        $productsByLocation = $this->productsByLocationPayload();
        $globalMaxRaw = AppSetting::query()->where('key', 'global_maximum_deductible')->value('value');
        $globalMaximumDeductible = ($globalMaxRaw !== null && trim((string) $globalMaxRaw) !== '')
            ? round((float) $globalMaxRaw, 2) : null;
        $user = auth()->user();

        $locationsQuery = Location::query()
            ->where('is_active', true)
            ->branchesAndWarehouses()
            ->orderBy('name');
        if ($this->invoiceFormBranchListIsRestricted()) {
            $locationsQuery->whereIn('id', $this->invoiceFormAccessibleBranchIds());
        }

        return view('sales.invoices.create', [
            'customers' => Customer::where('is_active', true)->orderBy('name')->get(['id', 'name', 'customer_code', 'phone', 'phone_numbers', 'default_guarantor_id']),
            'locations' => $locationsQuery->get(),
            'productsByLocation' => $productsByLocation,
            'globalMaximumDeductible' => $globalMaximumDeductible,
            'canApplyLineDiscount' => $user instanceof User && $user->can('apply-discount'),
            'canViewLineProfit' => $user instanceof User && $user->can('view-line-profit'),
            'referrerEmployees' => $this->invoiceReferrerEmployees(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateInvoiceInput($request, false);
        $this->assertLineProductsAvailableAtBranch((int) $data['branch_id'], $data['lines']);
        $preparedLines = $this->prepareLines($data['lines']);

        $invoice = null;
        DB::transaction(function () use ($data, $preparedLines, &$invoice): void {
            $invoice = $this->persistInvoice(null, $data, $preparedLines);
        });

        return redirect()->route('sales.invoices.index')->with('status', 'Invoice created as draft.');
    }

    public function show(SalesInvoice $invoice): View
    {
        $this->loadInvoiceShowData($invoice);
        $customers = Customer::where('is_active', true)->orderBy('name')->get(['id', 'name', 'customer_code', 'phone', 'phone_numbers', 'default_guarantor_id']);
        $branchChangeLocations = $this->branchChangeLocationOptions($invoice);

        $paymentMethodsForBranch = $this->availablePaymentMethodsForLocation((int) $invoice->branch_id);

        return view('sales.invoices.show', compact('invoice', 'customers', 'branchChangeLocations', 'paymentMethodsForBranch'));
    }

    public function print(SalesInvoice $invoice): View
    {
        $this->loadInvoiceShowData($invoice);
        $invoice->loadMissing(['installmentPlan.schedules.paymentAllocations.payment.paymentMethodRef']);

        $companyPad = $this->companyPadSettings();

        return view('sales.invoices.print', compact('invoice', 'companyPad'));
    }

    public function edit(SalesInvoice $invoice): RedirectResponse|View
    {
        $this->authorize('update', $invoice);

        if ($invoice->status === 'draft') {
            $invoice->load(['lines.product', 'lines.saleLineSerials.serialItem', 'customer.defaultGuarantor']);

            $globalMaxRaw = AppSetting::query()->where('key', 'global_maximum_deductible')->value('value');
            $globalMaximumDeductible = ($globalMaxRaw !== null && trim((string) $globalMaxRaw) !== '')
                ? round((float) $globalMaxRaw, 2) : null;
            $user = auth()->user();

            $locationsQuery = Location::query()
                ->where('is_active', true)
                ->branchesAndWarehouses()
                ->orderBy('name');
            if ($this->invoiceFormBranchListIsRestricted()) {
                $locationsQuery->whereIn('id', $this->invoiceFormAccessibleBranchIds());
            }

            return view('sales.invoices.edit', [
                'invoice' => $invoice,
                'postedEditMode' => false,
                'customers' => Customer::where('is_active', true)->orderBy('name')->get(['id', 'name', 'customer_code', 'phone', 'phone_numbers', 'default_guarantor_id']),
                'locations' => $locationsQuery->get(),
                'productsByLocation' => $this->productsByLocationPayload(),
                'referrerEmployees' => $this->invoiceReferrerEmployees(),
                'initialDraftLines' => $invoice->lines->map(function ($line): array {
                    return [
                        'product_id' => $line->product_id,
                        'qty' => $line->qty,
                        'unit_price' => (float) $line->unit_price,
                        'discount_type' => $line->discount_type ?: 'percent',
                        'discount_input' => $line->discount_input !== null ? (float) $line->discount_input : 0.0,
                        'service_charge_type' => $line->service_charge_type ?: 'fixed',
                        'service_charge_input' => $line->service_charge_input !== null ? (float) $line->service_charge_input : (float) ($line->service_charge ?? 0),
                        'serials' => $line->saleLineSerials->map(function ($sls): array {
                            $ser = $sls->serialItem;

                            return [
                                'id' => $sls->serial_item_id,
                                'serial_number' => $ser?->serial_number ?? '',
                                'label' => ($ser?->serial_number ?? '').' (MFG: '.($ser?->manufacturer_serial ?: 'N/A').')',
                            ];
                        })->values()->all(),
                    ];
                })->values()->all(),
                'globalMaximumDeductible' => $globalMaximumDeductible,
                'canApplyLineDiscount' => $user instanceof User && $user->can('apply-discount'),
                'canViewLineProfit' => $user instanceof User && $user->can('view-line-profit'),
            ]);
        }

        if (! in_array($invoice->status, ['posted', 'active'], true)) {
            return redirect()->route('sales.invoices.show', $invoice)
                ->with('status', 'This invoice cannot be edited.');
        }

        if ($invoice->sale_type === 'installment') {
            $this->authorize('reviseActive', $invoice);
        }

        $invoice->load(['lines.product', 'lines.saleLineSerials.serialItem', 'customer', 'branch', 'installmentPlan.schedules']);

        $globalMaxRaw = AppSetting::query()->where('key', 'global_maximum_deductible')->value('value');
        $globalMaximumDeductible = ($globalMaxRaw !== null && trim((string) $globalMaxRaw) !== '')
            ? round((float) $globalMaxRaw, 2) : null;
        $user = auth()->user();

        $locationsQuery = Location::query()
            ->where('is_active', true)
            ->branchesAndWarehouses()
            ->orderBy('name');
        if ($this->invoiceFormBranchListIsRestricted()) {
            $locationsQuery->whereIn('id', $this->invoiceFormAccessibleBranchIds());
        }

        $installmentAdminEditMode = $invoice->sale_type === 'installment';

        return view('sales.invoices.edit', [
            'invoice' => $invoice,
            'postedEditMode' => true,
            'installmentAdminEditMode' => $installmentAdminEditMode,
            'customers' => Customer::where('is_active', true)->orderBy('name')->get(['id', 'name', 'customer_code', 'phone', 'phone_numbers', 'default_guarantor_id']),
            'locations' => $locationsQuery->get(),
            'productsByLocation' => $this->productsByLocationPayload(),
            'initialDraftLines' => $invoice->lines->map(function ($line): array {
                return [
                    'product_id' => $line->product_id,
                    'qty' => $line->qty,
                    'unit_price' => (float) $line->unit_price,
                    'discount_type' => $line->discount_type ?: 'percent',
                    'discount_input' => $line->discount_input !== null ? (float) $line->discount_input : 0.0,
                    'service_charge_type' => $line->service_charge_type ?: 'fixed',
                    'service_charge_input' => $line->service_charge_input !== null ? (float) $line->service_charge_input : (float) ($line->service_charge ?? 0),
                    'serials' => $line->saleLineSerials->map(function ($sls): array {
                        $ser = $sls->serialItem;

                        return [
                            'id' => $sls->serial_item_id,
                            'serial_number' => $ser?->serial_number ?? '',
                            'label' => ($ser?->serial_number ?? '').' (MFG: '.($ser?->manufacturer_serial ?: 'N/A').')',
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
            'globalMaximumDeductible' => $globalMaximumDeductible,
            'canApplyLineDiscount' => $user instanceof User && $user->can('apply-discount'),
            'canViewLineProfit' => $user instanceof User && $user->can('view-line-profit'),
            'referrerEmployees' => $this->invoiceReferrerEmployees(),
        ]);
    }

    public function update(
        Request $request,
        SalesInvoice $invoice,
        SalesPostingService $postingService,
        JournalService $journalService,
        InventoryBalanceService $inventoryBalanceService,
        ActiveInstallmentInvoiceRevisionService $installmentRevisionService,
    ): RedirectResponse {
        $revisePosted = in_array($invoice->status, ['posted', 'active'], true);

        if ($revisePosted && $invoice->sale_type === 'installment') {
            $this->authorize('reviseActive', $invoice);
        } else {
            $this->authorize('update', $invoice);
        }

        $data = $request->validate(array_merge([
            'invoice_date' => ['required', 'date'],
            'branch_id' => ['required', $this->invoiceStockLocationExistsRule()],
            'customer_id' => ['required', 'exists:customers,id'],
            'referrer_id' => ['required', 'integer', 'exists:employees,id'],
            'sale_type' => ['required', 'in:default,credit,installment'],
            'credit_due_date' => ['nullable', 'date'],
            'installment_down_payment' => ['nullable', 'numeric', 'min:0'],
            'installment_count' => ['nullable', 'integer', 'min:1'],
            'installment_first_due_date' => ['nullable', 'date'],
            'installment_frequency' => ['nullable', 'in:daily,weekly,monthly,custom'],
            'installment_custom_days' => ['nullable', 'integer', 'min:1'],
            'installment_penalty_enabled' => ['nullable', 'boolean'],
            'installment_penalty_rate' => ['nullable', 'numeric', 'min:0'],
            'plan_grand_total' => ['nullable', 'numeric', 'min:0'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.serial_ids' => ['nullable', 'array'],
            'lines.*.serial_ids.*' => ['nullable', 'string', 'max:100'],
            'lines.*.discount_type' => ['nullable', 'in:percent,fixed'],
            'lines.*.discount_input' => ['nullable', 'numeric', 'min:0'],
            'lines.*.service_charge_type' => ['nullable', 'in:percent,fixed'],
            'lines.*.service_charge_input' => ['nullable', 'numeric', 'min:0'],
        ], $this->guarantorValidationRules()));

        $this->enforceInvoiceDateLimits($data['invoice_date']);

        if ($revisePosted) {
            $data['branch_id'] = (string) $invoice->branch_id;
            $data['customer_id'] = (string) $invoice->customer_id;
            $data['sale_type'] = $invoice->sale_type;
        }

        if ($revisePosted && $invoice->sale_type === 'installment') {
            if (empty($data['installment_count']) || empty($data['installment_first_due_date'])) {
                throw ValidationException::withMessages([
                    'installment_count' => 'Installment count and first due date are required.',
                ]);
            }

            if (($data['installment_frequency'] ?? '') === 'custom' && empty($data['installment_custom_days'])) {
                throw ValidationException::withMessages([
                    'installment_custom_days' => 'Custom frequency requires days interval.',
                ]);
            }

            $planTotal = round((float) ($data['plan_grand_total'] ?? 0), 2);
            if ($planTotal < 0.01) {
                throw ValidationException::withMessages([
                    'plan_grand_total' => 'Plan invoice amount is required.',
                ]);
            }

            $amountPaid = round((float) $invoice->amount_paid, 2);
            if ($planTotal + 0.001 < $amountPaid) {
                throw ValidationException::withMessages([
                    'plan_grand_total' => 'Plan invoice amount cannot be less than amount already paid ('.number_format($amountPaid, 2).').',
                ]);
            }

            $downPayment = round(max(0, (float) ($data['installment_down_payment'] ?? 0)), 2);
            if ($downPayment > $planTotal + 0.001) {
                throw ValidationException::withMessages([
                    'installment_down_payment' => 'Advance cannot exceed the plan invoice amount.',
                ]);
            }
        }

        $this->assertBranchIdAllowedForInvoice((int) $data['branch_id']);

        $actingUser = auth()->user();
        if (! $actingUser instanceof User || ! $actingUser->can('apply-discount')) {
            foreach (array_keys($data['lines']) as $idx) {
                $data['lines'][$idx]['discount_type'] = null;
                $data['lines'][$idx]['discount_input'] = null;
            }
        }

        // Installment sales cannot use line or invoice discounts.
        if (($data['sale_type'] ?? '') === 'installment') {
            foreach (array_keys($data['lines']) as $idx) {
                $data['lines'][$idx]['discount_type'] = null;
                $data['lines'][$idx]['discount_input'] = null;
            }
            $data['discount_total'] = 0;
        }

        if ($data['sale_type'] === 'credit' && empty($data['credit_due_date'])) {
            throw ValidationException::withMessages([
                'credit_due_date' => 'Due date is required for credit sales.',
            ]);
        }

        if (! $revisePosted && $data['sale_type'] === 'installment') {
            if (empty($data['installment_count']) || empty($data['installment_first_due_date'])) {
                throw ValidationException::withMessages([
                    'installment_count' => 'Installment count and first due date are required for installment sales.',
                ]);
            }

            if (($data['installment_frequency'] ?? '') === 'custom' && empty($data['installment_custom_days'])) {
                throw ValidationException::withMessages([
                    'installment_custom_days' => 'Custom frequency requires days interval.',
                ]);
            }
        }

        $preparedLines = $this->prepareLines($data['lines']);

        $stockCheckRows = collect($preparedLines)->map(fn (array $p): array => [
            'product_id' => $p['product']->id,
            'qty' => (int) $p['line']['qty'],
        ])->all();

        if ($revisePosted) {
            try {
                DB::transaction(function () use (
                    $request,
                    $invoice,
                    $data,
                    $preparedLines,
                    $stockCheckRows,
                    $actingUser,
                    $postingService,
                    $journalService,
                    $inventoryBalanceService,
                    $installmentRevisionService,
                ): void {
                    $invoiceModel = SalesInvoice::query()
                        ->lockForUpdate()
                        ->with(['lines.saleLineSerials.serialItem', 'returnRefunds', 'debtWriteoffs'])
                        ->findOrFail($invoice->id);

                    if (! in_array($invoiceModel->status, ['posted', 'active'], true)) {
                        throw ValidationException::withMessages([
                            'invoice' => 'Only posted invoices can be revised with this action.',
                        ]);
                    }

                    $isInstallmentRevise = $invoiceModel->sale_type === 'installment';

                    if ($invoiceModel->returnRefunds->isNotEmpty()) {
                        throw ValidationException::withMessages([
                            'invoice' => 'Cannot edit an invoice that has linked return refunds.',
                        ]);
                    }

                    if ($invoiceModel->debtWriteoffs->isNotEmpty()) {
                        throw ValidationException::withMessages([
                            'invoice' => 'Cannot edit an invoice that has debt write-off history.',
                        ]);
                    }

                    $oldGrandTotal = round((float) $invoiceModel->grand_total, 2);
                    $amountPaid = round((float) $invoiceModel->amount_paid, 2);
                    $taxTotal = round((float) $invoiceModel->tax_total, 2);
                    $branchId = (int) $invoiceModel->branch_id;

                    $linesPreviewSubtotal = $this->previewPreparedLinesSubtotal($preparedLines, $actingUser);
                    $discountTotal = round((float) ($data['discount_total'] ?? 0), 2);

                    if ($discountTotal > $linesPreviewSubtotal) {
                        throw ValidationException::withMessages([
                            'discount_total' => 'Discount cannot exceed the line subtotal ('.number_format($linesPreviewSubtotal, 2).').',
                        ]);
                    }

                    $grandPreview = $isInstallmentRevise
                        ? round((float) ($data['plan_grand_total'] ?? 0), 2)
                        : max(0.0, round($linesPreviewSubtotal - $discountTotal + $taxTotal, 2));

                    if ($grandPreview + 0.001 < $amountPaid) {
                        throw ValidationException::withMessages([
                            ($isInstallmentRevise ? 'plan_grand_total' : 'discount_total') => 'Grand total cannot be less than amount already applied ('.number_format($amountPaid, 2).'). Adjust lines/discount or reverse excess payments first.',
                        ]);
                    }

                    $touchedOld = $postingService->reversePostedSaleStock($invoiceModel);
                    $invoiceModel->refresh();

                    foreach ($touchedOld as $productId) {
                        $inventoryBalanceService->recalculate((int) $productId, $branchId);
                    }

                    $this->assertDraftSerialAssignmentsConsistent($preparedLines);
                    $this->assertLineProductsAvailableAtBranch($branchId, $stockCheckRows);

                    $this->createDraftLinesFromPrepared($invoiceModel, $data, $preparedLines);

                    $invoiceModel->refresh()->load('lines');

                    $linesSubtotal = round((float) $invoiceModel->lines->sum(fn ($line): float => (float) $line->line_total), 2);

                    if ($discountTotal > $linesSubtotal) {
                        throw ValidationException::withMessages([
                            'discount_total' => 'Discount cannot exceed the line subtotal ('.number_format($linesSubtotal, 2).').',
                        ]);
                    }

                    $grandTotal = $isInstallmentRevise
                        ? round((float) ($data['plan_grand_total'] ?? 0), 2)
                        : max(0.0, round($linesSubtotal - $discountTotal + $taxTotal, 2));

                    if ($grandTotal + 0.001 < $amountPaid) {
                        throw ValidationException::withMessages([
                            ($isInstallmentRevise ? 'plan_grand_total' : 'discount_total') => 'Grand total cannot be less than amount already applied ('.number_format($amountPaid, 2).').',
                        ]);
                    }

                    $amountDue = max(0.0, round($grandTotal - $amountPaid, 2));
                    $paymentStatus = $amountDue <= 0.001 ? 'paid' : ($amountPaid > 0.001 ? 'partial' : 'unpaid');

                    $headerUpdate = [
                        'invoice_date' => $data['invoice_date'],
                        'referrer_id' => $data['referrer_id'] ?? null,
                        'subtotal' => $linesSubtotal,
                        'discount_total' => $discountTotal,
                        'grand_total' => $grandTotal,
                    ];

                    if ($isInstallmentRevise) {
                        $headerUpdate = array_merge($headerUpdate, [
                            'installment_down_payment' => round(max(0, (float) ($data['installment_down_payment'] ?? 0)), 2),
                            'installment_count' => (int) $data['installment_count'],
                            'installment_first_due_date' => $data['installment_first_due_date'],
                            'installment_frequency' => $data['installment_frequency'] ?? 'monthly',
                            'installment_custom_days' => ($data['installment_frequency'] ?? '') === 'custom'
                                ? (int) $data['installment_custom_days']
                                : null,
                            'installment_penalty_enabled' => $request->boolean('installment_penalty_enabled'),
                            'installment_penalty_rate' => $data['installment_penalty_rate'] ?? 0.02,
                            'credit_due_date' => null,
                        ]);
                    } else {
                        $headerUpdate['credit_due_date'] = $invoiceModel->sale_type === 'credit'
                            ? ($data['credit_due_date'] ?? null)
                            : null;
                        $headerUpdate['amount_due'] = $amountDue;
                        $headerUpdate['payment_status'] = $paymentStatus;
                    }

                    $invoiceModel->update($headerUpdate);

                    $arRow = AccountsReceivableLedger::query()
                        ->where('reference_id', $invoiceModel->id)
                        ->where('event_type', 'invoice_posted')
                        ->where(function ($q): void {
                            $q->where('reference_type', SalesInvoice::class)
                                ->orWhere('reference_type', 'SalesInvoice');
                        })
                        ->first();

                    if (! $arRow) {
                        throw new InvalidStateException('Missing accounts receivable posting for this invoice.');
                    }

                    $arRow->update(['amount_delta' => $grandTotal]);

                    Customer::query()->whereKey((int) $invoiceModel->customer_id)->increment(
                        'outstanding_balance',
                        round($grandTotal - $oldGrandTotal, 2)
                    );

                    $journalService->refreshSalesInvoiceGl($invoiceModel->fresh(), (int) auth()->id());

                    $postingService->applyPostedSaleStock($invoiceModel->fresh(), (int) auth()->id());

                    if ($isInstallmentRevise) {
                        $installmentRevisionService->regeneratePlanFromInvoiceConfig(
                            $invoiceModel->fresh(),
                            (int) auth()->id(),
                        );

                        AuditLog::create([
                            'actor_user_id' => (int) auth()->id(),
                            'action' => 'sales_invoice.installment_plan_revised',
                            'entity_type' => 'SalesInvoice',
                            'entity_id' => $invoiceModel->id,
                            'before_snapshot' => null,
                            'after_snapshot' => [
                                'installment_count' => $invoiceModel->fresh()->installment_count,
                                'grand_total' => $grandTotal,
                            ],
                            'ip_address' => request()->ip(),
                        ]);
                    }
                });
            } catch (InvalidStateException $e) {
                return back()->withErrors(['invoice' => $e->getMessage()])->withInput();
            } catch (SerialLocationMismatchException $e) {
                return back()->with('status', 'Serial location mismatch for '.$e->getMessage().'.')->withInput();
            } catch (SerialNotAvailableException $e) {
                return back()->with('status', 'Serial not available: '.$e->getMessage())->withInput();
            } catch (SerialCountMismatchException $e) {
                return back()->with('status', $e->getMessage())->withInput();
            } catch (SerialSaleLedgerIntegrityException $e) {
                return back()->withErrors(['invoice' => $e->getMessage()])->with('status', $e->getMessage())->withInput();
            }

            $statusMessage = $invoice->sale_type === 'installment'
                ? 'Installment invoice and plan updated.'
                : 'Invoice updated.';

            return redirect()->route('sales.invoices.show', $invoice)->with('status', $statusMessage);
        }

        DB::transaction(function () use ($request, $invoice, $data, $preparedLines, $stockCheckRows): void {
            $newBranchId = (int) $data['branch_id'];

            $invoice = SalesInvoice::query()
                ->lockForUpdate()
                ->with(['lines.saleLineSerials.serialItem'])
                ->findOrFail($invoice->id);

            if ($invoice->status !== 'draft') {
                throw ValidationException::withMessages([
                    'invoice' => 'Only draft invoices can be updated.',
                ]);
            }

            foreach ($invoice->lines as $line) {
                $this->releaseSerialsForDraftInvoiceLine($line);
            }
            $invoice->lines()->delete();

            $this->assertDraftSerialAssignmentsConsistent($preparedLines);
            $this->assertLineProductsAvailableAtBranch($newBranchId, $stockCheckRows);

            $this->createDraftLinesFromPrepared($invoice, $data, $preparedLines);

            $invoice->refresh()->load('lines');

            $linesSubtotal = round((float) $invoice->lines->sum(fn ($line): float => (float) $line->line_total), 2);
            $discountTotal = round((float) ($data['discount_total'] ?? 0), 2);

            if ($discountTotal > $linesSubtotal) {
                throw ValidationException::withMessages([
                    'discount_total' => 'Discount cannot exceed the line subtotal ('.number_format($linesSubtotal, 2).').',
                ]);
            }

            $taxTotal = round((float) $invoice->tax_total, 2);
            $grandTotal = max(0.0, round($linesSubtotal - $discountTotal + $taxTotal, 2));
            $amountPaid = round((float) $invoice->amount_paid, 2);
            $advance = $data['sale_type'] === 'installment'
                ? round(max(0, (float) ($data['installment_down_payment'] ?? 0)), 2)
                : 0.0;
            $amountDue = max(0.0, round($grandTotal - $amountPaid - $advance, 2));
            $paymentStatus = $amountDue <= 0.001 ? 'paid' : ($amountPaid > 0.001 ? 'partial' : 'unpaid');

            $invoice->update([
                'invoice_date' => $data['invoice_date'],
                'branch_id' => $data['branch_id'],
                'customer_id' => $data['customer_id'],
                'referrer_id' => $data['referrer_id'] ?? null,
                'sale_type' => $data['sale_type'],
                'credit_due_date' => $data['sale_type'] === 'credit' ? $data['credit_due_date'] : null,
                'installment_down_payment' => $data['sale_type'] === 'installment' ? (float) ($data['installment_down_payment'] ?? 0) : null,
                'installment_count' => $data['sale_type'] === 'installment' ? (int) ($data['installment_count'] ?? 0) : null,
                'installment_first_due_date' => $data['sale_type'] === 'installment' ? $data['installment_first_due_date'] : null,
                'installment_frequency' => $data['sale_type'] === 'installment' ? ($data['installment_frequency'] ?? 'monthly') : null,
                'installment_custom_days' => $data['sale_type'] === 'installment' ? ($data['installment_custom_days'] ?? null) : null,
                'installment_penalty_enabled' => $data['sale_type'] === 'installment' ? $request->boolean('installment_penalty_enabled') : false,
                'installment_penalty_rate' => $data['sale_type'] === 'installment' ? ($data['installment_penalty_rate'] ?? 0.02) : null,
                'subtotal' => $linesSubtotal,
                'discount_total' => $discountTotal,
                'grand_total' => $grandTotal,
                'amount_due' => $amountDue,
                'payment_status' => $paymentStatus,
            ]);

            $this->processGuarantorAndVerifications($invoice, $data, $request);
        });

        return redirect()->route('sales.invoices.show', $invoice)->with('status', 'Draft invoice updated.');
    }

    public function post(Request $request, SalesInvoice $invoice, SalesPostingService $service): RedirectResponse
    {
        $availablePaymentMethods = $this->availablePaymentMethodsForLocation($invoice->branch_id);
        $availableMethodIds = $availablePaymentMethods->pluck('id')->map(fn ($id) => (int) $id)->all();

        $data = $request->validate([
            'payments' => ['nullable', 'array'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
            'payments.*.payment_method_id' => ['required_with:payments', 'integer', 'exists:payment_methods,id'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ]);

        foreach ($data['payments'] ?? [] as $index => $payment) {
            $paymentMethodId = (int) ($payment['payment_method_id'] ?? 0);
            if (! in_array($paymentMethodId, $availableMethodIds, true)) {
                throw ValidationException::withMessages([
                    "payments.{$index}.payment_method_id" => 'Selected payment method is not available for this location.',
                ]);
            }
        }

        if ($invoice->sale_type === 'credit' && ! $invoice->credit_due_date) {
            throw ValidationException::withMessages([
                'credit_due_date' => 'Credit sale requires a due date before posting.',
            ]);
        }

        if ($invoice->sale_type === 'installment' && (! $invoice->installment_count || ! $invoice->installment_first_due_date)) {
            throw ValidationException::withMessages([
                'installment_count' => 'Installment sale requires plan setup before posting.',
            ]);
        }

        $payments = collect($data['payments'] ?? [])
            ->filter(fn ($row) => (float) ($row['amount'] ?? 0) > 0)
            ->map(function (array $row): array {
                return [
                    'amount' => (float) $row['amount'],
                    'payment_method_id' => (int) $row['payment_method_id'],
                    'date' => now()->toDateString(),
                    'reference' => $row['reference'] ?? null,
                ];
            })
            ->values()
            ->all();

        if ($invoice->sale_type === 'default' && $payments !== []) {
            $paymentTotal = round(collect($payments)->sum('amount'), 2);
            $expected = round((float) $invoice->grand_total, 2);
            if (abs($paymentTotal - $expected) > 0.001) {
                throw ValidationException::withMessages([
                    'payments' => 'Cash sales must be paid in full at posting ('.number_format($expected, 2).').',
                ]);
            }
        }

        $advancePaymentMethodId = null;
        if ($invoice->sale_type === 'installment') {
            $downPayment = round(max(0, (float) ($invoice->installment_down_payment ?? 0)), 2);
            if ($downPayment > 0) {
                if ($availableMethodIds === []) {
                    throw ValidationException::withMessages([
                        'payments' => 'No active payment method with a wallet map is configured for this location.',
                    ]);
                }

                $advancePaymentMethodId = $availableMethodIds[0];
            }
        }

        try {
            if ($invoice->sale_type === 'installment') {
                $service->post($invoice, auth()->id(), []);
                $this->ensureInstallmentPlan($invoice->fresh(), auth()->id());

                if ($advancePaymentMethodId !== null) {
                    app(InstallmentAdvanceService::class)->recordAdvanceOnPosting(
                        $invoice->fresh(['installmentPlan.schedules']),
                        $advancePaymentMethodId,
                        auth()->id(),
                    );
                }

                $paymentService = app(PaymentService::class);
                foreach ($payments as $paymentData) {
                    $paymentService->recordAndAllocate(
                        $invoice->fresh(['installmentPlan.schedules']),
                        $paymentData,
                        auth()->id(),
                    );
                }
            } else {
                $service->post($invoice, auth()->id(), $payments);
            }
        } catch (SerialLocationMismatchException $e) {
            return back()->with('status', 'Serial location mismatch for '.$e->getMessage().'. Please verify invoice branch and selected serials.');
        } catch (SerialNotAvailableException $e) {
            return back()->with('status', 'Serial not available: '.$e->getMessage());
        } catch (SerialSaleLedgerIntegrityException $e) {
            return back()->with('status', 'Could not finalize stock for this invoice. '.$e->getMessage())->withErrors(['invoice' => $e->getMessage()]);
        }

        return back()->with('status', 'Invoice posted successfully.');
    }

    /**
     * Post a draft installment invoice (same rules as {@see post}) from the dedicated show-page action.
     */
    public function activateInstallment(Request $request, SalesInvoice $invoice, SalesPostingService $service): RedirectResponse
    {
        if ($invoice->sale_type !== 'installment') {
            return redirect()->route('sales.invoices.show', $invoice)
                ->with('status', 'This action is only for installment invoices.');
        }

        if ($invoice->status !== 'draft') {
            return redirect()->route('sales.invoices.show', $invoice)
                ->with('status', 'Only draft invoices can be activated.');
        }

        return $this->post($request, $invoice, $service);
    }

    public function updateGuarantor(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        if ($invoice->sale_type !== 'installment') {
            return redirect()->route('sales.invoices.show', $invoice)
                ->with('status', 'This action is only for installment invoices.');
        }

        $data = $request->validate($this->guarantorValidationRules());

        DB::transaction(function () use ($request, $invoice, $data): void {
            $this->processGuarantorAndVerifications($invoice, $data, $request);
        });

        return redirect()->route('sales.invoices.show', $invoice)->with('status', 'Guarantor and verification details updated successfully.');
    }

    public function updateBranch(Request $request, SalesInvoice $invoice, SalesInvoiceBranchChangeService $branchChangeService): RedirectResponse
    {
        $this->authorize('update', $invoice);

        $data = $request->validate([
            'branch_id' => ['required', $this->invoiceStockLocationExistsRule()],
        ]);

        $newBranchId = (int) $data['branch_id'];
        $this->assertBranchIdAllowedForInvoice($newBranchId);

        if ((int) $invoice->branch_id === $newBranchId) {
            return redirect()->route('sales.invoices.show', $invoice)
                ->with('status', 'Invoice is already assigned to that stock location.');
        }

        try {
            $branchChangeService->changeBranch($invoice, $newBranchId, (int) auth()->id());
        } catch (InvalidStateException $e) {
            return redirect()->route('sales.invoices.show', $invoice)
                ->withErrors(['branch_id' => $e->getMessage()]);
        }

        $status = $invoice->status === 'draft'
            ? 'Stock location updated. Re-assign serial numbers on the invoice before posting.'
            : 'Invoice stock location updated.';

        return redirect()->route('sales.invoices.show', $invoice)->with('status', $status);
    }

    public function changeSaleType(
        Request $request,
        SalesInvoice $invoice,
        PostedInvoiceSaleTypeChangeService $saleTypeChangeService,
    ): RedirectResponse {
        $this->authorize('changeSaleType', $invoice);

        $data = $request->validate([
            'sale_type' => ['required', 'in:default,credit'],
            'credit_due_date' => ['nullable', 'date'],
        ]);

        if ($data['sale_type'] === 'credit' && empty($data['credit_due_date'])) {
            throw ValidationException::withMessages([
                'credit_due_date' => 'Credit due date is required.',
            ]);
        }

        try {
            $saleTypeChangeService->change($invoice, $data, (int) $request->user()->id);
        } catch (InvalidStateException $e) {
            return redirect()->route('sales.invoices.show', $invoice)
                ->withErrors(['sale_type' => $e->getMessage()])
                ->withInput();
        }

        $message = match ($data['sale_type']) {
            'credit' => 'Invoice payment type updated to credit.',
            default => 'Invoice payment type updated to cash/default.',
        };

        return redirect()->route('sales.invoices.show', $invoice)->with('status', $message);
    }

    public function destroy(SalesInvoice $invoice): RedirectResponse
    {
        $this->authorize('delete', $invoice);

        $invoice->delete();

        return redirect()->route('sales.invoices.index')->with('status', 'Invoice moved to trash.');
    }

    public function trashed(Request $request): View
    {
        $this->authorize('viewTrashed', SalesInvoice::class);

        $filters = [
            'q' => trim((string) $request->input('q', '')),
        ];

        $invoices = SalesInvoice::onlyTrashed()
            ->with(['customer', 'branch'])
            ->when($this->contextLocationId() !== null, fn ($query) => $query->where('branch_id', $this->contextLocationId()))
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('invoice_number', 'like', "%{$keyword}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($keyword): void {
                            $customerQuery->where('name', 'like', "%{$keyword}%")
                                ->orWhere('phone', 'like', "%{$keyword}%");
                        });
                });
            })
            ->orderByDesc('deleted_at')
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        return view('sales.invoices.trashed', compact('invoices', 'filters'));
    }

    public function restore(SalesInvoice $invoice): RedirectResponse
    {
        $this->authorize('restore', $invoice);

        if (! $invoice->trashed()) {
            return redirect()->route('sales.invoices.trashed')->with('status', 'Invoice is not in the trash.');
        }

        $invoice->restore();

        return redirect()->route('sales.invoices.show', $invoice)->with('status', 'Invoice restored.');
    }

    public function forceDelete(SalesInvoice $invoice): RedirectResponse
    {
        $this->authorize('forceDelete', $invoice);

        if (! $invoice->trashed()) {
            return redirect()->route('sales.invoices.trashed')->with('status', 'Move the invoice to trash before permanently deleting it.');
        }

        try {
            DB::transaction(function () use ($invoice): void {
                $lockedInvoice = SalesInvoice::withTrashed()
                    ->lockForUpdate()
                    ->with([
                        'lines.saleLineSerials.serialItem',
                        'returnRefunds',
                        'debtWriteoffs',
                        'installmentPlan',
                    ])
                    ->findOrFail($invoice->id);

                if ($lockedInvoice->returnRefunds->isNotEmpty()) {
                    throw new \RuntimeException('Cannot delete invoice with linked return refunds.');
                }

                if ($lockedInvoice->debtWriteoffs->isNotEmpty()) {
                    throw new \RuntimeException('Cannot delete invoice with debt write-off history.');
                }

                $branchId = (int) $lockedInvoice->branch_id;
                $customerId = (int) $lockedInvoice->customer_id;
                $lineIds = $lockedInvoice->lines->pluck('id')->map(fn ($id) => (int) $id)->all();
                $touchedProducts = [];

                foreach ($lockedInvoice->lines as $line) {
                    $touchedProducts[(int) $line->product_id] = true;

                    foreach ($line->saleLineSerials as $lineSerial) {
                        $serial = $lineSerial->serialItem()->lockForUpdate()->first();
                        if (! $serial) {
                            continue;
                        }

                        if ((int) $serial->current_location_id !== $branchId) {
                            throw new \RuntimeException('Cannot safely delete invoice because one or more linked serials are no longer in the invoice branch.');
                        }

                        $reversibleStates = $lockedInvoice->status === 'draft'
                            ? ['in_stock', 'reserved']
                            : ['reserved', 'sold'];

                        if (! in_array($serial->status, $reversibleStates, true)) {
                            throw new \RuntimeException('Cannot safely delete invoice because one or more linked serials are no longer reversible.');
                        }

                        if ($serial->status === 'reserved' || (int) $serial->sale_invoice_line_id === (int) $line->id) {
                            $serial->update([
                                'status' => 'in_stock',
                                'sale_invoice_line_id' => null,
                                'warranty_start_date' => null,
                                'warranty_end_date' => null,
                            ]);
                        }
                    }
                }

                $paymentIds = PaymentAllocation::query()
                    ->where('sales_invoice_id', $lockedInvoice->id)
                    ->pluck('payment_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                if ($paymentIds !== []) {
                    InstallmentPaymentAllocation::query()->whereIn('payment_id', $paymentIds)->delete();

                    AccountsReceivableLedger::query()
                        ->whereIn('reference_id', $paymentIds)
                        ->where(function ($q): void {
                            $q->where('reference_type', Payment::class)
                                ->orWhere('reference_type', 'Payment');
                        })
                        ->delete();

                    $paymentEntryIds = JournalEntry::query()
                        ->where('reference_type', Payment::class)
                        ->whereIn('reference_id', $paymentIds)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();

                    if ($paymentEntryIds !== []) {
                        JournalLine::query()->whereIn('journal_entry_id', $paymentEntryIds)->delete();
                        JournalEntry::query()->whereIn('id', $paymentEntryIds)->delete();
                    }
                }

                PaymentAllocation::query()->where('sales_invoice_id', $lockedInvoice->id)->delete();

                if ($paymentIds !== []) {
                    foreach ($paymentIds as $paymentId) {
                        $hasOtherLinks = PaymentAllocation::query()->where('payment_id', $paymentId)->exists()
                            || InstallmentPaymentAllocation::query()->where('payment_id', $paymentId)->exists();

                        if (! $hasOtherLinks) {
                            Payment::query()->whereKey($paymentId)->delete();
                        }
                    }
                }

                AccountsReceivableLedger::query()
                    ->where('reference_id', $lockedInvoice->id)
                    ->where(function ($q): void {
                        $q->where('reference_type', SalesInvoice::class)
                            ->orWhere('reference_type', 'SalesInvoice');
                    })
                    ->delete();

                StockLedger::query()
                    ->where('reference_id', $lockedInvoice->id)
                    ->where(function ($q): void {
                        $q->where('reference_type', 'SalesInvoice')
                            ->orWhere('reference_type', SalesInvoice::class);
                    })
                    ->delete();

                $invoiceEntryIds = JournalEntry::query()
                    ->where('reference_type', SalesInvoice::class)
                    ->where('reference_id', $lockedInvoice->id)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if ($invoiceEntryIds !== []) {
                    JournalLine::query()->whereIn('journal_entry_id', $invoiceEntryIds)->delete();
                    JournalEntry::query()->whereIn('id', $invoiceEntryIds)->delete();
                }

                if ($lineIds !== []) {
                    SaleLineSerial::query()->whereIn('sales_invoice_line_id', $lineIds)->delete();
                }

                $lockedInvoice->lines()->delete();

                if ($lockedInvoice->installmentPlan) {
                    InstallmentSchedule::query()->where('installment_plan_id', $lockedInvoice->installmentPlan->id)->delete();
                    $lockedInvoice->installmentPlan()->delete();
                }

                $lockedInvoice->forceDelete();

                /** @var InventoryBalanceService $inventoryBalanceService */
                $inventoryBalanceService = app(InventoryBalanceService::class);
                foreach (array_keys($touchedProducts) as $productId) {
                    $inventoryBalanceService->recalculate((int) $productId, $branchId);
                }

                $outstanding = (float) (AccountsReceivableLedger::query()
                    ->where('customer_id', $customerId)
                    ->sum('amount_delta') ?? 0);
                Customer::query()
                    ->whereKey($customerId)
                    ->update(['outstanding_balance' => round($outstanding, 2)]);
            });
        } catch (\Throwable $e) {
            return redirect()
                ->route('sales.invoices.trashed')
                ->with('status', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to delete invoice safely.');
        }

        return redirect()->route('sales.invoices.trashed')->with('status', 'Invoice permanently deleted with rollback.');
    }

    public function writeOff(Request $request, SalesInvoice $invoice, DebtWriteoffService $debtWriteoffService): RedirectResponse
    {
        Gate::authorize('write-off-debt');
        $this->authorize('view', $invoice);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $debtWriteoffService->writeOffInvoiceReceivable(
                $invoice,
                (float) $data['amount'],
                isset($data['reason']) ? trim((string) $data['reason']) : null,
                (int) $request->user()->id,
            );
        } catch (InvalidStateException $e) {
            return back()->withErrors(['write_off' => $e->getMessage()])->withInput();
        }

        return redirect()->route('sales.invoices.show', $invoice)
            ->with('status', 'Receivable written off.');
    }

    public function destroyWriteOff(SalesInvoice $invoice, InvoiceDebtWriteoff $writeoff, DebtWriteoffService $debtWriteoffService): RedirectResponse
    {
        Gate::authorize('remove-debt-writeoff');
        $this->authorize('view', $invoice);

        if ((int) $writeoff->sales_invoice_id !== (int) $invoice->id) {
            abort(404);
        }

        try {
            $debtWriteoffService->reverseInvoiceDebtWriteoff($writeoff, (int) auth()->id());
        } catch (InvalidStateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.invoices.show', $invoice)
            ->with('status', 'Write-off removed.');
    }

    public function quickCreateCustomer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20'],
            'phone_numbers' => ['nullable', 'array'],
            'phone_numbers.*' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string'],
        ]);

        $phoneNumbers = $this->parsePhoneNumbersList($data['phone_numbers'] ?? []);

        $customer = Customer::create([
            'customer_code' => $this->generateCustomerCode(),
            'name' => $data['name'],
            'phone' => $data['phone'],
            'phone_numbers' => $phoneNumbers,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'is_active' => true,
        ]);

        return response()->json([
            'data' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'customer_code' => $customer->customer_code,
                'phone' => $customer->phone,
                'phones' => $customer->normalizedPhoneNumbers(),
                'label' => $customer->name.' ('.implode(', ', $customer->normalizedPhoneNumbers()).')',
            ],
        ], 201);
    }

    private function generateCustomerCode(): string
    {
        do {
            $code = $this->documentNumbers->next(
                DocumentNumberGenerator::TYPE_CUSTOMER,
                'CUST',
                now(),
                'ymd',
                4,
            );
        } while (Customer::where('customer_code', $code)->exists());

        return $code;
    }

    public function serialSuggestions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', $this->invoiceStockLocationExistsRule()],
            'product_id' => ['required', 'exists:products,id'],
            'query' => ['nullable', 'string', 'max:100'],
            'exclude_ids' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->assertBranchIdAllowedForInvoice((int) $data['branch_id']);

        $keyword = trim((string) ($data['query'] ?? ''));

        $excludeIds = [];
        if (! empty($data['exclude_ids'])) {
            $excludeIds = array_values(array_unique(array_filter(array_map(
                static fn (string $chunk): int => (int) trim($chunk),
                explode(',', (string) $data['exclude_ids'])
            ), static fn (int $id): bool => $id > 0)));
        }

        $serials = SerialItem::query()
            ->where('current_location_id', $data['branch_id'])
            ->where('product_id', $data['product_id'])
            ->where('status', 'in_stock')
            ->when($excludeIds !== [], static fn ($query) => $query->whereNotIn('id', $excludeIds))
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('serial_number', 'like', "%{$keyword}%")
                        ->orWhere('manufacturer_serial', 'like', "%{$keyword}%");

                    if (ctype_digit($keyword)) {
                        $subQuery->orWhere('id', (int) $keyword);

                        $labelCode = \App\Support\SerialLabelCode::parseScanCode($keyword);
                        if ($labelCode !== null) {
                            $subQuery->orWhere('label_code', $labelCode);
                        }
                    }
                });
            })
            ->orderBy('serial_number')
            ->limit(50)
            ->get(['id', 'serial_number', 'manufacturer_serial', 'sale_price'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'serial_number' => $row->serial_number,
                'manufacturer_serial' => $row->manufacturer_serial,
                'sale_price' => $row->sale_price !== null ? (float) $row->sale_price : null,
                'label' => $row->serial_number.' (MFG: '.($row->manufacturer_serial ?: 'N/A').')',
            ])
            ->values();

        return response()->json(['data' => $serials]);
    }

    /**
     * Map a wedge scan to an in-stock serialized unit at the selected branch
     * (printed 5-digit label code, internal serial, or manufacturer serial).
     */
    public function resolveSerialScan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', $this->invoiceStockLocationExistsRule()],
            'code' => ['required', 'string', 'max:120'],
        ]);

        $this->assertBranchIdAllowedForInvoice((int) $data['branch_id']);

        $code = trim($data['code']);
        if ($code === '') {
            return response()->json(['data' => null]);
        }

        $labelCode = \App\Support\SerialLabelCode::parseScanCode($code);

        $matches = SerialItem::query()
            ->where('current_location_id', $data['branch_id'])
            ->where('status', 'in_stock')
            ->where(function ($query) use ($code, $labelCode): void {
                $query->where('serial_number', $code)
                    ->orWhere('manufacturer_serial', $code);

                if ($labelCode !== null) {
                    $query->orWhere('label_code', $labelCode);
                }
            })
            ->orderBy('id')
            ->limit(5)
            ->get(['id', 'product_id', 'serial_number', 'manufacturer_serial', 'sale_price']);

        if ($matches->isEmpty()) {
            return response()->json(['data' => null]);
        }

        if ($matches->count() > 1) {
            return response()->json([
                'data' => null,
                'error' => 'Multiple in-stock units matched this code. Pick the serial manually on the line.',
            ]);
        }

        $row = $matches->first();

        return response()->json([
            'data' => [
                'product_id' => (int) $row->product_id,
                'serial' => [
                    'id' => (int) $row->id,
                    'serial_number' => $row->serial_number,
                    'manufacturer_serial' => $row->manufacturer_serial,
                    'sale_price' => $row->sale_price !== null ? (float) $row->sale_price : null,
                    'label' => $row->serial_number.' (MFG: '.($row->manufacturer_serial ?: 'N/A').')',
                ],
            ],
        ]);
    }

    /**
     * Validates that the selected location may sell from stock (branch or warehouse).
     */
    private function invoiceStockLocationExistsRule(): Exists
    {
        return Rule::exists('locations', 'id')->where(function (QueryBuilder $query): void {
            $query->whereIn('type', ['branch', 'warehouse']);
        });
    }

    /**
     * Locations the current user may use on the invoice form (branches and warehouses; create / edit / serial API).
     *
     * @return array<int, int>
     */
    private function invoiceFormAccessibleBranchIds(): array
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return [];
        }

        if ($user->isAdmin() || $user->hasPermission('location.view-all')) {
            return Location::query()
                ->where('is_active', true)
                ->branchesAndWarehouses()
                ->orderBy('name')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return $user->accessibleLocations()
            ->where('is_active', true)
            ->whereIn('locations.type', ['branch', 'warehouse'])
            ->orderBy('locations.name')
            ->pluck('locations.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function invoiceFormBranchListIsRestricted(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ! $user->isAdmin()
            && ! $user->hasPermission('location.view-all');
    }

    private function assertBranchIdAllowedForInvoice(int $branchId): void
    {
        $allowed = $this->invoiceFormAccessibleBranchIds();
        if ($allowed === [] || ! in_array($branchId, $allowed, true)) {
            throw ValidationException::withMessages([
                'branch_id' => 'You do not have access to this branch or warehouse.',
            ]);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, Location>
     */
    private function branchChangeLocationOptions(SalesInvoice $invoice): \Illuminate\Support\Collection
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $user->can('update', $invoice)) {
            return collect();
        }

        if (! in_array($invoice->status, ['draft', 'posted', 'active'], true)) {
            return collect();
        }

        $locationsQuery = Location::query()
            ->where('is_active', true)
            ->branchesAndWarehouses()
            ->whereKeyNot($invoice->branch_id)
            ->orderBy('name');

        if ($this->invoiceFormBranchListIsRestricted()) {
            $locationsQuery->whereIn('id', $this->invoiceFormAccessibleBranchIds());
        }

        return $locationsQuery->get();
    }

    private function enforceInvoiceDateLimits(string $date): void
    {
        $invoiceDate = Carbon::parse($date)->startOfDay();
        $today = Carbon::today();

        if ($invoiceDate->isAfter($today)) {
            throw ValidationException::withMessages([
                'invoice_date' => 'Invoice date cannot be in the future.',
            ]);
        }

        $actor = auth()->user();
        $canBackdate = $actor instanceof User && $actor->can('backdate-sales-invoice');

        if (! $canBackdate && $invoiceDate->isBefore($today->copy()->subDays(2))) {
            throw ValidationException::withMessages([
                'invoice_date' => 'Invoice date cannot be more than 2 days in the past.',
            ]);
        }
    }

    /**
     * @return array<string, list<array{id: int, sku: string, barcode: string, name: string, label: string, price: float, is_serialized: bool, available_qty: int, on_hand_qty: int, reserved_qty: int, search: string}>>
     */
    private function productsByLocationPayload(): array
    {
        $branchIds = $this->invoiceFormAccessibleBranchIds();
        $grouped = [];
        $includePurchasePrice = auth()->user() instanceof User && auth()->user()->can('view-line-profit');

        foreach ($branchIds as $branchId) {
            $branchLedgerBalances = DB::query()
                ->fromSub(LedgerBackedInventory::balancesSubquery(), 'lb')
                ->where('lb.location_id', '=', $branchId);

            $select = [
                'products.id as product_id',
                DB::raw('COALESCE(inventory_balances.on_hand_qty, 0) as on_hand_qty'),
                DB::raw('COALESCE(inventory_balances.reserved_qty, 0) as reserved_qty'),
                DB::raw('COALESCE(inventory_balances.available_qty, 0) as available_qty'),
                'products.sku',
                'products.barcode',
                'products.name',
                'products.model_number',
                'products.default_sale_price',
                'products.maximum_deductible',
                'products.is_serialized',
            ];
            if ($includePurchasePrice) {
                $select[] = 'products.default_purchase_price';
            }

            $rows = Product::query()
                ->where('products.is_active', true)
                ->leftJoinSub($branchLedgerBalances, 'inventory_balances', function ($join): void {
                    $join->on('inventory_balances.product_id', '=', 'products.id');
                })
                ->orderByRaw('CASE WHEN COALESCE(inventory_balances.available_qty, 0) > 0 THEN 0 ELSE 1 END')
                ->orderBy('products.name')
                ->select($select)
                ->get();

            $key = (string) $branchId;
            foreach ($rows as $row) {
                $availableQty = (int) $row->available_qty;
                $onHandQty = (int) $row->on_hand_qty;
                $reservedQty = (int) $row->reserved_qty;
                $sku = (string) $row->sku;
                $name = (string) $row->name;
                $barcode = (string) ($row->barcode ?? '');
                $model = (string) ($row->model_number ?? '');
                $item = [
                    'id' => (int) $row->product_id,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'name' => $name,
                    'label' => $sku.' - '.$name,
                    'price' => (float) $row->default_sale_price,
                    'maximum_deductible' => $row->maximum_deductible !== null ? (float) $row->maximum_deductible : null,
                    'is_serialized' => (bool) $row->is_serialized,
                    'available_qty' => $availableQty,
                    'on_hand_qty' => $onHandQty,
                    'reserved_qty' => $reservedQty,
                    'search' => strtolower(trim($sku.' '.$name.' '.$barcode.' '.$model)),
                ];
                if ($includePurchasePrice) {
                    $item['purchase_price'] = (float) ($row->default_purchase_price ?? 0);
                }
                $grouped[$key][] = $item;
            }
        }

        return $grouped;
    }

    /**
     * Computes per-line discount and net total (exclusive of invoice-level discount_total).
     *
     * @return array{discount_type: string|null, discount_input: float|null, discount_amount: float, line_total: float}
     */
    private function lineDiscountPricing(
        Product $product,
        int $qty,
        float $unitPrice,
        mixed $discountTypeRaw,
        mixed $discountInputRaw,
        mixed $actor,
        string $errorDotPath,
    ): array {
        $lineGross = round(max(0, $qty * $unitPrice), 2);

        if (! $actor instanceof User || ! $actor->can('apply-discount')) {
            return [
                'discount_type' => null,
                'discount_input' => null,
                'discount_amount' => 0.0,
                'line_total' => $lineGross,
            ];
        }

        $norm = $this->lineDiscountLimits->normalizeDiscountTypeInput(
            $discountTypeRaw !== null ? (string) $discountTypeRaw : null,
            $discountInputRaw ?? 0
        );
        $applied = round($this->lineDiscountLimits->appliedDiscountAmount($norm['type'], $norm['input'], $lineGross), 2);

        if ($applied <= 0) {
            return [
                'discount_type' => null,
                'discount_input' => null,
                'discount_amount' => 0.0,
                'line_total' => $lineGross,
            ];
        }

        if ($this->lineDiscountLimits->exceedsNonOverrideLimit($product, $applied, $lineGross)
            && ! $this->lineDiscountLimits->userMayOverrideMaximum($actor)) {
            $meta = $this->lineDiscountLimits->getMaxDiscount($product, $lineGross);
            $hint = ($meta['unlimited'] ?? false)
                ? 'Discount for this line is not allowed.'
                : 'Discount exceeds the maximum allowed for this product ('.number_format((float) $meta['max_amount'], 2).' on line before tax). Increase your limit on the product, set a higher global cap in Company pad settings, or use a role with permission to exceed maximum discounts.';

            throw ValidationException::withMessages([
                $errorDotPath.'.discount_input' => $hint,
            ]);
        }

        return [
            'discount_type' => $norm['type'],
            'discount_input' => $norm['input'],
            'discount_amount' => $applied,
            'line_total' => round(max(0, $lineGross - $applied), 2),
        ];
    }

    /**
     * @param  list<array{product_id: mixed, qty: mixed}>  $lines
     */
    private function assertLineProductsAvailableAtBranch(int $branchId, array $lines): void
    {
        $productIds = collect($lines)->pluck('product_id')->unique()->map(fn ($id) => (int) $id)->all();

        $balances = DB::query()
            ->fromSub(LedgerBackedInventory::balancesSubquery(), 'b')
            ->where('b.location_id', $branchId)
            ->whereIn('b.product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        foreach ($lines as $index => $line) {
            $productId = (int) $line['product_id'];
            $qty = (int) $line['qty'];
            $available = (int) ($balances->get($productId)->available_qty ?? 0);
            $product = $products->get($productId);

            if ($available < 1) {
                throw ValidationException::withMessages([
                    "lines.{$index}.product_id" => 'Selected product is not available at this branch.',
                ]);
            }

            if ($available < $qty) {
                $label = $product ? $product->sku.' - '.$product->name : 'Product';

                throw ValidationException::withMessages([
                    "lines.{$index}.qty" => "{$label}: quantity exceeds available stock at this branch ({$available}).",
                ]);
            }
        }
    }

    private function validateInvoiceInput(Request $request, bool $includePostNow): array
    {
        $rules = [
            'invoice_date' => ['required', 'date'],
            'branch_id' => ['required', $this->invoiceStockLocationExistsRule()],
            'customer_id' => ['required', 'exists:customers,id'],
            'referrer_id' => ['required', 'integer', 'exists:employees,id'],
            'sale_type' => ['required', 'in:default,credit,installment'],
            'credit_due_date' => ['nullable', 'date'],
            'installment_down_payment' => ['nullable', 'numeric', 'min:0'],
            'installment_count' => ['nullable', 'integer', 'min:1'],
            'installment_first_due_date' => ['nullable', 'date'],
            'installment_frequency' => ['nullable', 'in:daily,weekly,monthly,custom'],
            'installment_custom_days' => ['nullable', 'integer', 'min:1'],
            'installment_penalty_enabled' => ['nullable', 'boolean'],
            'installment_penalty_rate' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.serial_ids' => ['nullable', 'array'],
            'lines.*.serial_ids.*' => ['nullable', 'string', 'max:100'],
            'lines.*.discount_type' => ['nullable', 'in:percent,fixed'],
            'lines.*.discount_input' => ['nullable', 'numeric', 'min:0'],
            'lines.*.service_charge_type' => ['nullable', 'in:percent,fixed'],
            'lines.*.service_charge_input' => ['nullable', 'numeric', 'min:0'],
        ];

        $rules = array_merge($rules, $this->guarantorValidationRules());

        if ($includePostNow) {
            $rules['post_now'] = ['nullable', 'boolean'];
            $rules['payment_amount'] = ['nullable', 'numeric', 'min:0.01'];
            $rules['payment_method_id'] = ['nullable', 'integer', 'exists:payment_methods,id'];
            $rules['payment_reference'] = ['nullable', 'string', 'max:100'];
        }

        $data = $request->validate($rules);

        $this->enforceInvoiceDateLimits($data['invoice_date']);

        $this->assertBranchIdAllowedForInvoice((int) $data['branch_id']);

        if ($data['sale_type'] === 'credit' && empty($data['credit_due_date'])) {
            throw ValidationException::withMessages([
                'credit_due_date' => 'Due date is required for credit sales.',
            ]);
        }

        if ($data['sale_type'] === 'installment') {
            if (empty($data['installment_count']) || empty($data['installment_first_due_date'])) {
                throw ValidationException::withMessages([
                    'installment_count' => 'Installment count and first due date are required for installment sales.',
                ]);
            }

            if (($data['installment_frequency'] ?? '') === 'custom' && empty($data['installment_custom_days'])) {
                throw ValidationException::withMessages([
                    'installment_custom_days' => 'Custom frequency requires days interval.',
                ]);
            }
        }

        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->can('apply-discount')) {
            foreach (array_keys($data['lines'] ?? []) as $i) {
                $data['lines'][$i]['discount_type'] = null;
                $data['lines'][$i]['discount_input'] = null;
            }
        }

        if (($data['sale_type'] ?? '') === 'installment') {
            foreach (array_keys($data['lines'] ?? []) as $i) {
                $data['lines'][$i]['discount_type'] = null;
                $data['lines'][$i]['discount_input'] = null;
            }
            $data['discount_total'] = 0;
        }

        return $data;
    }

    private function prepareLines(array $lines): array
    {
        $productIds = collect($lines)->pluck('product_id')->unique()->values();
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $preparedLines = [];

        foreach ($lines as $lineIndex => $lineData) {
            $product = $products->get((int) $lineData['product_id']);

            if (! $product) {
                throw ValidationException::withMessages([
                    "lines.{$lineIndex}.product_id" => 'Invalid product selected.',
                ]);
            }

            $serialInputs = collect($lineData['serial_ids'] ?? [])
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn ($value) => $value !== '')
                ->unique()
                ->values()
                ->all();

            $resolvedSerialIds = $this->resolveSerialIds($serialInputs);

            if ($product->is_serialized && count($resolvedSerialIds) !== (int) $lineData['qty']) {
                throw ValidationException::withMessages([
                    "lines.{$lineIndex}.serial_ids" => 'Serialized product requires serial count equal to quantity.',
                ]);
            }

            if (! $product->is_serialized && count($resolvedSerialIds) > 0) {
                throw ValidationException::withMessages([
                    "lines.{$lineIndex}.serial_ids" => 'Non-serialized product should not include serial ids.',
                ]);
            }

            $preparedLines[] = [
                'line' => $lineData,
                'product' => $product,
                'serial_ids' => $resolvedSerialIds,
            ];
        }

        return $preparedLines;
    }

    private function resolveSerialIds(array $serialInputs): array
    {
        if ($serialInputs === []) {
            return [];
        }

        $numericSerialIds = collect($serialInputs)
            ->filter(fn ($value) => ctype_digit($value))
            ->map(fn ($value) => (int) $value)
            ->values();

        $serialNumbers = collect($serialInputs)
            ->filter(fn ($value) => ! ctype_digit($value))
            ->values();

        return SerialItem::query()
            ->where(function ($query) use ($numericSerialIds, $serialNumbers): void {
                if ($numericSerialIds->isNotEmpty()) {
                    $query->whereIn('id', $numericSerialIds->all());
                }

                if ($serialNumbers->isNotEmpty()) {
                    if ($numericSerialIds->isNotEmpty()) {
                        $query->orWhereIn('serial_number', $serialNumbers->all());
                    } else {
                        $query->whereIn('serial_number', $serialNumbers->all());
                    }
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{line: array, product: Product, serial_ids: array<int, int>}>  $preparedLines
     */
    private function previewPreparedLinesSubtotal(array $preparedLines, ?User $actor): float
    {
        $sum = 0.0;

        foreach ($preparedLines as $idx => $prepared) {
            $product = $prepared['product'];
            $lineData = $prepared['line'];
            $qty = (int) $lineData['qty'];
            $unitPrice = (float) $lineData['unit_price'];
            $pricing = $this->lineDiscountPricing(
                $product,
                $qty,
                $unitPrice,
                $lineData['discount_type'] ?? null,
                $lineData['discount_input'] ?? null,
                $actor,
                'lines.'.$idx
            );
            $svc = $this->lineServiceChargePricing(
                $pricing,
                $lineData['service_charge_type'] ?? null,
                $lineData['service_charge_input'] ?? ($lineData['service_charge'] ?? 0)
            );
            $sum = round($sum + $svc['line_total'], 2);
        }

        return $sum;
    }

    /**
     * Each serial may have only one active (unreleased) {@see SaleLineSerial}. Catch duplicate
     * picks across multiple lines (or serials still allocated to another draft) before inserts.
     *
     * @param  array<int, array{line: array, product: Product, serial_ids: array<int, int>}>  $preparedLines
     */
    private function assertDraftSerialAssignmentsConsistent(array $preparedLines): void
    {
        $allSerialIds = [];
        foreach ($preparedLines as $prepared) {
            foreach ($prepared['serial_ids'] as $sid) {
                $allSerialIds[] = (int) $sid;
            }
        }

        if ($allSerialIds === []) {
            return;
        }

        if (count($allSerialIds) !== count(array_unique($allSerialIds))) {
            throw ValidationException::withMessages([
                'lines' => 'Each serial number can only be used once on this invoice. Remove the duplicate from one of the lines.',
            ]);
        }

        $uniqueIds = array_values(array_unique($allSerialIds));
        $alreadyLinked = SaleLineSerial::query()
            ->whereIn('serial_item_id', $uniqueIds)
            ->whereNull('released_at')
            ->pluck('serial_item_id')
            ->all();

        if ($alreadyLinked !== []) {
            throw ValidationException::withMessages([
                'lines' => 'One or more serial numbers are already assigned to another invoice. Choose different units or cancel the other draft first.',
            ]);
        }
    }

    private function releaseSerialsForDraftInvoiceLine(SalesInvoiceLine $line): void
    {
        $line->loadMissing('saleLineSerials.serialItem');

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

    /**
     * @param  array<int, array{line: array, product: Product, serial_ids: array<int, int>}>  $preparedLines
     */
    private function createDraftLinesFromPrepared(SalesInvoice $invoice, array $data, array $preparedLines): float
    {
        $linesNetSubtotal = 0.0;
        $actor = auth()->user();

        foreach ($preparedLines as $idx => $prepared) {
            $product = $prepared['product'];
            $lineData = $prepared['line'];
            $qty = (int) $lineData['qty'];
            $unitPrice = (float) $lineData['unit_price'];
            $pricing = $this->lineDiscountPricing(
                $product,
                $qty,
                $unitPrice,
                $lineData['discount_type'] ?? null,
                $lineData['discount_input'] ?? null,
                $actor,
                'lines.'.$idx
            );
            $svc = $this->lineServiceChargePricing(
                $pricing,
                $lineData['service_charge_type'] ?? null,
                $lineData['service_charge_input'] ?? ($lineData['service_charge'] ?? 0)
            );
            $lineTotal = $svc['line_total'];
            $linesNetSubtotal = round($linesNetSubtotal + $lineTotal, 2);

            $line = $invoice->lines()->create([
                'product_id' => $product->id,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount_type' => $pricing['discount_type'],
                'discount_input' => $pricing['discount_input'],
                'discount_amount' => $pricing['discount_amount'],
                'service_charge_type' => $svc['service_charge_type'],
                'service_charge_input' => $svc['service_charge_input'],
                'service_charge' => $svc['service_charge_amount'],
                'tax_amount' => 0,
                'line_total' => $lineTotal,
                'warranty_months_snapshot' => $product->warranty_months,
            ]);

            if (! $product->is_serialized) {
                continue;
            }

            $serials = SerialItem::query()
                ->whereIn('id', $prepared['serial_ids'])
                ->where('product_id', $product->id)
                ->where('current_location_id', $data['branch_id'])
                ->where('status', 'in_stock')
                ->lockForUpdate()
                ->get();

            if ($serials->count() !== count($prepared['serial_ids'])) {
                throw ValidationException::withMessages([
                    "lines.{$idx}.serial_ids" => 'One or more serials are not available at selected branch.',
                ]);
            }

            foreach ($serials as $serial) {
                SaleLineSerial::create([
                    'sales_invoice_line_id' => $line->id,
                    'serial_item_id' => $serial->id,
                ]);

                // Draft invoices keep the serial assignment without putting stock on hold.
            }
        }

        return $linesNetSubtotal;
    }

    /**
     * @return array{type: string, input: float}
     */
    private function normalizeServiceChargeTypeInput(mixed $typeRaw, mixed $inputRaw): array
    {
        $type = strtolower(trim((string) $typeRaw));
        if (! in_array($type, ['percent', 'fixed'], true)) {
            $type = 'fixed';
        }
        $input = is_numeric($inputRaw) ? (float) $inputRaw : 0.0;

        return ['type' => $type, 'input' => round(max(0, $input), 2)];
    }

    private function appliedServiceChargeAmount(string $type, float $input, float $baseAmount): float
    {
        $baseAmount = max(0, round($baseAmount, 2));
        $input = max(0, round($input, 4));

        if ($type === 'percent') {
            return round($baseAmount * ($input / 100), 2);
        }

        return round($input, 2);
    }

    /**
     * Computes the service charge for a line on top of its (already discounted) line total.
     *
     * @param  array{line_total: float}  $pricing
     * @return array{service_charge_type: string, service_charge_input: float, service_charge_amount: float, line_total: float}
     */
    private function lineServiceChargePricing(array $pricing, mixed $typeRaw, mixed $inputRaw): array
    {
        $norm = $this->normalizeServiceChargeTypeInput($typeRaw, $inputRaw);
        $amount = $this->appliedServiceChargeAmount($norm['type'], $norm['input'], $pricing['line_total']);

        return [
            'service_charge_type' => $norm['type'],
            'service_charge_input' => $norm['input'],
            'service_charge_amount' => $amount,
            'line_total' => round($pricing['line_total'] + $amount, 2),
        ];
    }

    private function persistInvoice(?SalesInvoice $invoice, array $data, array $preparedLines): SalesInvoice
    {
        $this->assertDraftSerialAssignmentsConsistent($preparedLines);

        $invoice = $invoice ?? new SalesInvoice;

        if (! $invoice->exists) {
            $invoice->created_by = auth()->id();
            $invoice->status = 'draft';
        }

        $invoice->fill([
            'invoice_date' => $data['invoice_date'],
            'branch_id' => $data['branch_id'],
            'customer_id' => $data['customer_id'],
            'referrer_id' => $data['referrer_id'] ?? null,
            'sale_type' => $data['sale_type'],
            'credit_due_date' => $data['sale_type'] === 'credit' ? $data['credit_due_date'] : null,
            'installment_down_payment' => $data['sale_type'] === 'installment' ? (float) ($data['installment_down_payment'] ?? 0) : null,
            'installment_count' => $data['sale_type'] === 'installment' ? (int) ($data['installment_count'] ?? 0) : null,
            'installment_first_due_date' => $data['sale_type'] === 'installment' ? $data['installment_first_due_date'] : null,
            'installment_frequency' => $data['sale_type'] === 'installment' ? ($data['installment_frequency'] ?? 'monthly') : null,
            'installment_custom_days' => $data['sale_type'] === 'installment' ? ($data['installment_custom_days'] ?? null) : null,
            'installment_penalty_enabled' => $data['sale_type'] === 'installment' ? request()->boolean('installment_penalty_enabled') : false,
            'installment_penalty_rate' => $data['sale_type'] === 'installment' ? ($data['installment_penalty_rate'] ?? 0.02) : null,
        ]);
        $invoice->save();

        $this->processGuarantorAndVerifications($invoice, $data, request());

        $linesNetSubtotal = $this->createDraftLinesFromPrepared($invoice, $data, $preparedLines);

        $advance = $data['sale_type'] === 'installment'
            ? round(max(0, (float) ($data['installment_down_payment'] ?? 0)), 2)
            : 0.0;

        $invoice->forceFill([
            'subtotal' => $linesNetSubtotal,
            'grand_total' => $linesNetSubtotal,
            'amount_due' => max(0, round($linesNetSubtotal - $advance, 2)),
        ]);
        $invoice->save();

        AuditLog::create([
            'actor_user_id' => auth()->id(),
            'action' => 'sales_invoice.created_draft',
            'entity_type' => 'SalesInvoice',
            'entity_id' => $invoice->id,
            'before_snapshot' => null,
            'after_snapshot' => [
                'status' => $invoice->status,
                'grand_total' => $invoice->grand_total,
                'amount_due' => $invoice->amount_due,
                'sale_type' => $invoice->sale_type,
            ],
            'ip_address' => request()->ip(),
        ]);

        return $invoice;
    }

    private function ensureInstallmentPlan(SalesInvoice $invoice, int $actorUserId): void
    {
        if ($invoice->sale_type !== 'installment') {
            return;
        }

        if ($invoice->installmentPlan()->exists()) {
            app(PaymentService::class)->allocateUnlinkedPaymentsToInstallmentPlan($invoice);
            app(InstallmentInvoiceSyncService::class)->syncInvoiceHeaderFromPlan(
                $invoice->fresh(['installmentPlan.schedules']),
            );

            return;
        }

        $count = (int) ($invoice->installment_count ?? 0);
        if ($count < 1 || ! $invoice->installment_first_due_date) {
            throw new InvalidStateException('Installment configuration is incomplete.');
        }

        $downPayment = (float) ($invoice->installment_down_payment ?? 0);
        $financedAmount = max((float) $invoice->grand_total - $downPayment, 0);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'total_amount' => $invoice->grand_total,
            'down_payment' => $downPayment,
            'financed_amount' => $financedAmount,
            'remaining_amount' => $financedAmount,
            'penalty_enabled' => (bool) $invoice->installment_penalty_enabled,
            'penalty_rate' => (float) ($invoice->installment_penalty_rate ?? 0.02),
            'created_by' => $actorUserId,
        ]);

        if ($financedAmount <= 0) {
            return;
        }

        $baseAmount = round($financedAmount / $count, 2);
        $scheduledTotal = $baseAmount * $count;
        $delta = round($financedAmount - $scheduledTotal, 2);
        $startDate = Carbon::parse($invoice->installment_first_due_date);
        $frequency = (string) ($invoice->installment_frequency ?? 'monthly');
        $customDays = (int) ($invoice->installment_custom_days ?? 0);

        for ($i = 1; $i <= $count; $i++) {
            $amount = $baseAmount;
            if ($i === $count) {
                $amount = round($amount + $delta, 2);
            }

            InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => $i,
                'due_date' => $this->computeInstallmentDueDate($startDate, $i - 1, $frequency, $customDays)->toDateString(),
                'due_amount' => $amount,
            ]);
        }

        app(PaymentService::class)->allocateUnlinkedPaymentsToInstallmentPlan($invoice->fresh(['installmentPlan.schedules']));
        app(InstallmentInvoiceSyncService::class)->syncInvoiceHeaderFromPlan(
            $invoice->fresh(['installmentPlan.schedules']),
        );
    }

    private function computeInstallmentDueDate(Carbon $startDate, int $offset, string $frequency, int $customDays): Carbon
    {
        return match ($frequency) {
            'daily' => $startDate->copy()->addDays($offset),
            'weekly' => $startDate->copy()->addWeeks($offset),
            'custom' => $startDate->copy()->addDays($offset * max($customDays, 1)),
            default => $startDate->copy()->addMonths($offset),
        };
    }

    private function availablePaymentMethodsForLocation(?int $locationId)
    {
        return PaymentMethod::availableForSalesAtLocation($locationId);
    }

    private function loadInvoiceShowData(SalesInvoice $invoice): void
    {
        $invoice->load([
            'customer.defaultGuarantor',
            'guarantor',
            'referrer',
            'branch',
            'lines.product.brand',
            'lines.saleLineSerials.serialItem',
            'installmentPlan.schedules',
            'debtWriteoffs.actor',
            'returnOrders.customer',
            'returnOrders.branch',
            'payments' => fn ($q) => $q->orderByDesc('payments.payment_date')->orderByDesc('payments.id'),
            'payments.paymentMethodRef',
            'payments.referrerEmployee',
            'recordedInboundPaymentAllocations.payment.paymentMethodRef',
            'recordedInboundPaymentAllocations.payment.referrerEmployee',
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
            'company_pad_footer_left_label' => 'Customer Signature',
            'company_pad_footer_right_label' => 'Authorized Signature',
        ];

        foreach ($defaults as $key => $value) {
            $stored[$key] = ($stored[$key] !== null && $stored[$key] !== '') ? (string) $stored[$key] : $value;
        }

        /** @var array<string, string> $stored */
        return $stored;
    }

    /**
     * @return array<int, string>
     */
    private function parsePhoneNumbersList(mixed $raw): array
    {
        $parts = is_array($raw) ? $raw : preg_split('/[\r\n,]+/', (string) $raw);
        $parts = is_array($parts) ? $parts : [];

        return collect($parts)
            ->map(static fn ($value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function guarantorValidationRules(): array
    {
        return [
            'guarantor_source' => ['nullable', 'in:keep,existing,new,none'],
            'guarantor_id' => [
                'required_if:guarantor_source,existing',
                'nullable',
                'exists:customers,id',
            ],
            'guarantor_name' => [
                'required_if:guarantor_source,new',
                'nullable',
                'string',
                'max:150',
            ],
            'guarantor_phone' => [
                'required_if:guarantor_source,new',
                'nullable',
                'string',
                'max:20',
            ],
            'guarantor_email' => [
                'nullable',
                'email',
                'max:150',
            ],
            'guarantor_nid_number' => [
                'nullable',
                'string',
                'max:30',
            ],
            'guarantor_address' => [
                'nullable',
                'string',
            ],
            'guarantor_notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'customer_nid' => [
                'nullable',
                'file',
                'max:10240',
            ],
            'guarantor_nid' => [
                'nullable',
                'file',
                'max:10240',
            ],
            'new_attachments' => [
                'nullable',
                'array',
            ],
            'new_attachments.*' => [
                'file',
                'max:10240',
            ],
            'remove_customer_nid' => [
                'nullable',
                'boolean',
            ],
            'remove_guarantor_nid' => [
                'nullable',
                'boolean',
            ],
            'remove_attachments' => [
                'nullable',
                'array',
            ],
            'remove_attachments.*' => [
                'string',
            ],
        ];
    }

    private function processGuarantorAndVerifications(SalesInvoice $invoice, array $data, Request $request): void
    {
        if (($data['sale_type'] ?? $invoice->sale_type) === 'installment') {
            $source = $data['guarantor_source'] ?? null;

            if ($source === null || $source === '') {
                throw ValidationException::withMessages([
                    'guarantor_source' => 'Please select a guarantor option.',
                ]);
            }

            if ($source === 'existing') {
                if (empty(trim((string) ($data['guarantor_address'] ?? '')))) {
                    throw ValidationException::withMessages([
                        'guarantor_address' => 'Guarantor address is required when selecting an existing customer.',
                    ]);
                }
                if (empty(trim((string) ($data['guarantor_nid_number'] ?? '')))) {
                    throw ValidationException::withMessages([
                        'guarantor_nid_number' => 'Guarantor NID number is required when selecting an existing customer.',
                    ]);
                }
                if (!empty($data['guarantor_id'])) {
                    $customer = Customer::findOrFail($data['guarantor_id']);
                    Customer::copyGuarantorToInvoice(
                        $customer,
                        $invoice,
                        $data['guarantor_notes'] ?? null,
                        $data['guarantor_nid_number'],
                        $data['guarantor_address'],
                    );
                }
            } elseif ($source === 'new') {
                $phone = trim($data['guarantor_phone'] ?? '');
                $customer = Customer::where('phone', $phone)->first();
                if (!$customer) {
                    $code = null;
                    for ($i = 0; $i < 10; $i++) {
                        $tempCode = 'G-' . strtoupper(Str::random(6));
                        if (!Customer::where('customer_code', $tempCode)->exists()) {
                            $code = $tempCode;
                            break;
                        }
                    }
                    if (!$code) {
                        $code = 'G-' . time();
                    }

                    $customer = Customer::create([
                        'dizicashier_uuid' => (string) Str::uuid(),
                        'customer_code' => $code,
                        'name' => $data['guarantor_name'],
                        'phone' => $phone,
                        'email' => $data['guarantor_email'] ?? null,
                        'address' => $data['guarantor_address'] ?? null,
                        'id_number' => $data['guarantor_nid_number'] ?? null,
                        'branch_id' => $invoice->branch_id,
                        'is_active' => true,
                    ]);
                }
                Customer::copyGuarantorToInvoice(
                    $customer,
                    $invoice,
                    $data['guarantor_notes'] ?? null,
                    $data['guarantor_nid_number'] ?? null,
                    $data['guarantor_address'] ?? null,
                );
            } elseif ($source === 'none') {
                $invoice->loadMissing('customer.defaultGuarantor');
                if ($invoice->customer?->default_guarantor_id && $invoice->customer->defaultGuarantor) {
                    Customer::copyGuarantorToInvoice($invoice->customer->defaultGuarantor, $invoice);
                } else {
                    $invoice->guarantor_id = null;
                    $invoice->guarantor_name = null;
                    $invoice->guarantor_address = null;
                    $invoice->guarantor_phone_numbers = [];
                    $invoice->guarantor_notes = null;
                    $invoice->guarantor_nid_number = null;
                }
            }
        } else {
            $invoice->guarantor_id = null;
            $invoice->guarantor_name = null;
            $invoice->guarantor_address = null;
            $invoice->guarantor_phone_numbers = [];
            $invoice->guarantor_notes = null;
            $invoice->guarantor_nid_number = null;
        }

        // Customer NID upload/delete
        if ($request->hasFile('customer_nid')) {
            if ($invoice->customer_nid_path) {
                Storage::disk('public')->delete($invoice->customer_nid_path);
            }
            $invoice->customer_nid_path = $request->file('customer_nid')->store('verifications/customer_nid', 'public');
        } elseif ($request->boolean('remove_customer_nid')) {
            if ($invoice->customer_nid_path) {
                Storage::disk('public')->delete($invoice->customer_nid_path);
            }
            $invoice->customer_nid_path = null;
        }

        // Guarantor NID upload/delete
        if ($request->hasFile('guarantor_nid')) {
            if ($invoice->guarantor_nid_path) {
                Storage::disk('public')->delete($invoice->guarantor_nid_path);
            }
            $invoice->guarantor_nid_path = $request->file('guarantor_nid')->store('verifications/guarantor_nid', 'public');
        } elseif ($request->boolean('remove_guarantor_nid')) {
            if ($invoice->guarantor_nid_path) {
                Storage::disk('public')->delete($invoice->guarantor_nid_path);
            }
            $invoice->guarantor_nid_path = null;
        }

        // Other attachments
        $attachments = is_array($invoice->verification_attachments) ? $invoice->verification_attachments : [];

        if ($request->has('remove_attachments') && is_array($request->input('remove_attachments'))) {
            foreach ($request->input('remove_attachments') as $pathToRemove) {
                if (in_array($pathToRemove, $attachments, true)) {
                    Storage::disk('public')->delete($pathToRemove);
                    $attachments = array_values(array_filter($attachments, fn($p) => $p !== $pathToRemove));
                }
            }
        }

        if ($request->hasFile('new_attachments')) {
            foreach ($request->file('new_attachments') as $file) {
                if ($file->isValid()) {
                    $path = $file->store('verifications/attachments', 'public');
                    $attachments[] = $path;
                }
            }
        }

        $invoice->verification_attachments = $attachments;
        $invoice->save();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Employee>
     */
    private function invoiceReferrerEmployees()
    {
        return \App\Models\Employee::query()
            ->where('is_active', true)
            ->orderBy('employee_code')
            ->orderBy('first_name')
            ->get(['id', 'employee_code', 'first_name', 'last_name']);
    }
}
