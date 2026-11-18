<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Http\Controllers\Concerns\SyncsPublicPhotoUpload;
use App\Models\Customer;
use App\Models\Location;
use App\Models\ReturnOrder;
use App\Models\SalesInvoice;
use App\Models\ServiceJob;
use App\Services\DocumentNumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerController extends Controller
{
    use AppliesInventoryLocationContext;
    use SyncsPublicPhotoUpload;

    public function __construct(
        private readonly DocumentNumberGenerator $documentNumbers,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'code' => trim((string) $request->input('code', '')),
            'is_active' => (string) $request->input('is_active', ''),
            'branch_id' => (string) $request->input('branch_id', ''),
            'grade' => (string) $request->input('grade', ''),
        ];

        $editId = $request->integer('edit');
        $editingCustomer = $editId ? Customer::with(['branch', 'defaultGuarantor'])->find($editId) : null;
        $branchesForForm = $this->customerBranchOptions();
        $guarantorCandidates = $this->guarantorCandidateCustomers($editingCustomer);
        $customers = Customer::query()
            ->with(['branch', 'riskSnapshot'])
            ->when($filters['grade'] !== '', fn ($query) => $query->whereHas('riskSnapshot', fn ($s) => $s->where('grade', $filters['grade'])))
            ->when($filters['code'] !== '', function ($query) use ($filters): void {
                $code = $filters['code'];
                $query->where(function ($subQuery) use ($code): void {
                    $subQuery->where('customer_code', $code)
                        ->orWhere('customer_code', 'like', $code.'%');
                });
            })
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('customer_code', 'like', "%{$keyword}%")
                        ->orWhere('name', 'like', "%{$keyword}%")
                        ->orWhere('phone', 'like', "%{$keyword}%")
                        ->orWhere('phone_numbers', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%");
                });
            })
            ->when($filters['is_active'] !== '', fn ($query) => $query->where('is_active', $filters['is_active'] === '1'))
            ->when($filters['branch_id'] !== '', function ($query) use ($filters, $branchesForForm): void {
                $bid = (int) $filters['branch_id'];
                if ($branchesForForm->pluck('id')->contains($bid)) {
                    $query->where('branch_id', $bid);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->latest()
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $kpis = [
            'total' => Customer::count(),
            'active' => Customer::where('is_active', true)->count(),
            'inactive' => Customer::where('is_active', false)->count(),
            'outstanding_total' => (float) Customer::sum('outstanding_balance'),
            'filtered' => $customers->total(),
        ];

        return view('customers.index', compact('customers', 'editingCustomer', 'filters', 'kpis', 'branchesForForm', 'guarantorCandidates'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('customers.index', ['drawer' => 'create']);
    }

    public function show(Request $request, Customer $customer): View
    {
        $customer->load(['branch', 'defaultGuarantor']);
        $salesInvoicesBase = SalesInvoice::query()
            ->where('customer_id', $customer->id)
            ->tap(fn ($q) => $this->scopeContextBranch($q));

        $salesInvoices = (clone $salesInvoicesBase)
            ->with('branch')
            ->latest('invoice_date')
            ->latest('id')
            ->paginate($this->resolvePerPage($request, 10), ['*'], 'invoices_page')
            ->withQueryString();

        $returnOrdersBase = ReturnOrder::query()
            ->where('customer_id', $customer->id)
            ->tap(fn ($q) => $this->scopeContextBranch($q));

        $returnOrders = (clone $returnOrdersBase)
            ->with(['branch', 'salesInvoice'])
            ->latest('return_date')
            ->latest('id')
            ->paginate($this->resolvePerPage($request, 10), ['*'], 'returns_page')
            ->withQueryString();

        $serviceJobsBase = ServiceJob::query()
            ->where('customer_id', $customer->id)
            ->tap(fn ($q) => $this->scopeContextBranch($q));

        $serviceJobs = (clone $serviceJobsBase)
            ->with(['branch', 'serialItem'])
            ->latest('intake_date')
            ->latest('id')
            ->paginate($this->resolvePerPage($request, 10), ['*'], 'jobs_page')
            ->withQueryString();

        $totals = [
            'sales_invoices' => (int) (clone $salesInvoicesBase)->count(),
            'sales_grand_total' => (float) ((clone $salesInvoicesBase)->sum('grand_total') ?? 0),
            'sales_due_total' => (float) ((clone $salesInvoicesBase)->sum('amount_due') ?? 0),
            'returns' => (int) (clone $returnOrdersBase)->count(),
            'refund_due_total' => (float) ((clone $returnOrdersBase)->sum('refund_due_amount') ?? 0),
            'service_jobs' => (int) (clone $serviceJobsBase)->count(),
            'service_jobs_open' => (int) (clone $serviceJobsBase)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->count(),
        ];

        $user = $request->user();
        $customerActivityScopeNote = null;
        if ($this->contextLocationId() !== null) {
            $customerActivityScopeNote = 'Sales, returns, and service jobs below are filtered to your current branch context. Customer details at the top apply company-wide.';
        } elseif ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
            $customerActivityScopeNote = 'Sales, returns, and service jobs below are limited to branches you can access. Customer profile above is shared across branches.';
        }

        $attachableInstallmentInvoices = SalesInvoice::query()
            ->where('customer_id', $customer->id)
            ->where('sale_type', 'installment')
            ->whereIn('status', ['draft', 'active'])
            ->whereNull('guarantor_id')
            ->where(function ($query): void {
                $query->whereNull('guarantor_name')
                    ->orWhere('guarantor_name', '');
            })
            ->latest('invoice_date')
            ->latest('id')
            ->limit(20)
            ->get(['id', 'invoice_number', 'invoice_date', 'status', 'grand_total']);

        return view('customers.show', compact(
            'customer',
            'salesInvoices',
            'returnOrders',
            'serviceJobs',
            'totals',
            'customerActivityScopeNote',
            'attachableInstallmentInvoices',
        ));
    }

    public function attachGuarantorToInvoice(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $data = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:sales_invoices,id'],
        ]);

        if (! $customer->default_guarantor_id) {
            return redirect()
                ->route('customers.show', $customer)
                ->withErrors(['invoice_id' => 'Set a default guarantor on this customer first.']);
        }

        $invoice = SalesInvoice::query()->findOrFail((int) $data['invoice_id']);

        try {
            $customer->applyDefaultGuarantorToInvoice($invoice);
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('customers.show', $customer)
                ->withErrors(['invoice_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sales.invoices.show', $invoice)
            ->with('status', 'Default guarantor attached to invoice.');
    }

    public function quickCreateGuarantor(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string'],
        ]);

        $guarantor = Customer::create([
            'customer_code' => $this->generateCustomerCode('G'),
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'branch_id' => $customer->branch_id,
            'is_active' => true,
        ]);

        $customer->update(['default_guarantor_id' => $guarantor->id]);

        return response()->json([
            'data' => [
                'id' => $guarantor->id,
                'name' => $guarantor->name,
                'customer_code' => $guarantor->customer_code,
                'phone' => $guarantor->phone,
                'label' => $guarantor->name.' ('.$guarantor->customer_code.($guarantor->phone ? ' · '.$guarantor->phone : '').')',
            ],
        ], 201);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->mergeNullableBranchId($request);

        $data = $request->validate(array_merge([
            'customer_code' => ['required', 'string', 'max:20', 'unique:customers,customer_code'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20'],
            'phone_numbers' => ['nullable', 'array'],
            'phone_numbers.*' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'branch_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('is_active', 1)],
            'default_guarantor_id' => ['nullable', 'integer', 'exists:customers,id'],
            'new_guarantor_name' => ['nullable', 'string', 'max:150'],
            'new_guarantor_phone' => ['nullable', 'string', 'max:20'],
            'new_guarantor_email' => ['nullable', 'email', 'max:150'],
            'new_guarantor_address' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'id_type' => ['nullable', 'string', 'max:40'],
            'id_number' => ['nullable', 'string', 'max:80'],
            'is_active' => ['nullable', 'boolean'],
            'nid_file' => ['nullable', 'file', 'max:10240'],
            'new_attachments' => ['nullable', 'array'],
            'new_attachments.*' => ['file', 'max:10240'],
        ], $this->photoUploadRules()));

        $this->assertGuarantorInputIsValid($request, null);
        $this->assertUserMayAssignCustomerBranch(isset($data['branch_id']) ? (int) $data['branch_id'] : null);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['phone_numbers'] = $this->parsePhoneNumbers($request->input('phone_numbers', []));
        $data['default_guarantor_id'] = $this->resolveDefaultGuarantorId($request, null, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        unset(
            $data['photo'],
            $data['remove_photo'],
            $data['nid_file'],
            $data['new_attachments'],
            $data['new_guarantor_name'],
            $data['new_guarantor_phone'],
            $data['new_guarantor_email'],
            $data['new_guarantor_address'],
        );

        $customer = Customer::create($data);

        $photoState = $this->resolvedPhotoPathState($request, 'photos/customers/'.$customer->id, null);
        $customer->forceFill($photoState);

        // Process NID file
        if ($request->hasFile('nid_file')) {
            $customer->nid_path = $request->file('nid_file')->store('customers/nid/'.$customer->id, 'public');
        }

        // Process attachments
        $attachments = [];
        if ($request->hasFile('new_attachments')) {
            foreach ($request->file('new_attachments') as $file) {
                if ($file->isValid()) {
                    $attachments[] = $file->store('customers/attachments/'.$customer->id, 'public');
                }
            }
        }
        $customer->attachments = $attachments;
        $customer->save();

        return redirect()->route('customers.index')->with('status', 'Customer created successfully.');
    }

    public function edit(Customer $customer): RedirectResponse
    {
        return redirect()->route('customers.index', ['drawer' => 'edit', 'edit' => $customer->id]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->mergeNullableBranchId($request);

        $data = $request->validate(array_merge([
            'customer_code' => ['required', 'string', 'max:20', 'unique:customers,customer_code,'.$customer->id],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20'],
            'phone_numbers' => ['nullable', 'array'],
            'phone_numbers.*' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'branch_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('is_active', 1)],
            'default_guarantor_id' => ['nullable', 'integer', 'exists:customers,id', Rule::notIn([$customer->id])],
            'new_guarantor_name' => ['nullable', 'string', 'max:150'],
            'new_guarantor_phone' => ['nullable', 'string', 'max:20'],
            'new_guarantor_email' => ['nullable', 'email', 'max:150'],
            'new_guarantor_address' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'id_type' => ['nullable', 'string', 'max:40'],
            'id_number' => ['nullable', 'string', 'max:80'],
            'is_active' => ['nullable', 'boolean'],
            'nid_file' => ['nullable', 'file', 'max:10240'],
            'new_attachments' => ['nullable', 'array'],
            'new_attachments.*' => ['file', 'max:10240'],
            'remove_nid' => ['nullable', 'boolean'],
            'remove_attachments' => ['nullable', 'array'],
            'remove_attachments.*' => ['string'],
        ], $this->photoUploadRules()));

        $this->assertGuarantorInputIsValid($request, $customer);
        $this->assertUserMayAssignCustomerBranch(isset($data['branch_id']) ? (int) $data['branch_id'] : null);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['phone_numbers'] = $this->parsePhoneNumbers($request->input('phone_numbers', []));
        $data['default_guarantor_id'] = $this->resolveDefaultGuarantorId(
            $request,
            $customer,
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
        );

        $photoState = $this->resolvedPhotoPathState(
            $request,
            'photos/customers/'.$customer->id,
            $customer->photo_path,
        );

        unset(
            $data['photo'],
            $data['remove_photo'],
            $data['nid_file'],
            $data['new_attachments'],
            $data['remove_nid'],
            $data['remove_attachments'],
            $data['new_guarantor_name'],
            $data['new_guarantor_phone'],
            $data['new_guarantor_email'],
            $data['new_guarantor_address'],
        );

        $customer->fill($data);
        $customer->forceFill($photoState);

        // Process NID file upload/remove
        if ($request->hasFile('nid_file')) {
            if ($customer->nid_path) {
                Storage::disk('public')->delete($customer->nid_path);
            }
            $customer->nid_path = $request->file('nid_file')->store('customers/nid/'.$customer->id, 'public');
        } elseif ($request->boolean('remove_nid')) {
            if ($customer->nid_path) {
                Storage::disk('public')->delete($customer->nid_path);
            }
            $customer->nid_path = null;
        }

        // Process other attachments upload/remove
        $attachments = is_array($customer->attachments) ? $customer->attachments : [];

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
                    $attachments[] = $file->store('customers/attachments/'.$customer->id, 'public');
                }
            }
        }

        $customer->attachments = $attachments;
        $customer->save();

        return redirect()->route('customers.index')->with('status', 'Customer updated successfully.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->delete();

        return redirect()->route('customers.index')->with('status', 'Customer deleted.');
    }

    /**
     * Active branch/location options the current user may assign or filter by.
     *
     * @return Collection<int, Location>
     */
    private function customerBranchOptions(): Collection
    {
        $query = Location::query()->where('is_active', true)->orderBy('name');
        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
            $ids = $user->accessibleLocationIds();
            if ($ids === []) {
                return collect();
            }
            $query->whereIn('id', $ids);
        }

        return $query->get(['id', 'code', 'name']);
    }

    private function mergeNullableBranchId(Request $request): void
    {
        $raw = $request->input('branch_id');
        if ($raw === null || $raw === '') {
            $request->merge(['branch_id' => null]);

            return;
        }
        $request->merge(['branch_id' => (int) $raw]);
    }

    private function assertUserMayAssignCustomerBranch(?int $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        $user = auth()->user();
        if (! $user || $user->isAdmin() || $user->hasPermission('location.view-all')) {
            return;
        }

        if (! in_array($branchId, $user->accessibleLocationIds(), true)) {
            throw ValidationException::withMessages([
                'branch_id' => __('You cannot assign this branch.'),
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function parsePhoneNumbers(mixed $raw): array
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

    /**
     * @return Collection<int, Customer>
     */
    private function guarantorCandidateCustomers(?Customer $exclude = null): Collection
    {
        return Customer::query()
            ->where('is_active', true)
            ->when($exclude, fn ($query) => $query->whereKeyNot($exclude->id))
            ->orderBy('name')
            ->get(['id', 'name', 'customer_code', 'phone']);
    }

    private function assertGuarantorInputIsValid(Request $request, ?Customer $customer): void
    {
        $hasExisting = trim((string) $request->input('default_guarantor_id', '')) !== '';
        $hasNew = trim((string) $request->input('new_guarantor_name', '')) !== ''
            || trim((string) $request->input('new_guarantor_phone', '')) !== '';

        if ($hasExisting && $hasNew) {
            throw ValidationException::withMessages([
                'default_guarantor_id' => 'Choose an existing guarantor or enter new guarantor details, not both.',
            ]);
        }

        if ($hasNew && trim((string) $request->input('new_guarantor_name', '')) === '') {
            throw ValidationException::withMessages([
                'new_guarantor_name' => 'Guarantor name is required when creating a new guarantor.',
            ]);
        }

        if ($hasNew && trim((string) $request->input('new_guarantor_phone', '')) === '') {
            throw ValidationException::withMessages([
                'new_guarantor_phone' => 'Guarantor phone is required when creating a new guarantor.',
            ]);
        }

        $guarantorId = (int) $request->input('default_guarantor_id');
        if ($hasExisting && $customer && $guarantorId === (int) $customer->id) {
            throw ValidationException::withMessages([
                'default_guarantor_id' => 'A customer cannot be their own guarantor.',
            ]);
        }
    }

    private function resolveDefaultGuarantorId(Request $request, ?Customer $customer, ?int $branchId): ?int
    {
        if ($request->input('guarantor_mode') === 'none') {
            return null;
        }

        $newName = trim((string) $request->input('new_guarantor_name', ''));
        $newPhone = trim((string) $request->input('new_guarantor_phone', ''));

        if ($newName !== '' || $newPhone !== '') {
            $guarantor = Customer::create([
                'customer_code' => $this->generateCustomerCode('G'),
                'name' => $newName,
                'phone' => $newPhone,
                'email' => $request->input('new_guarantor_email') ?: null,
                'address' => $request->input('new_guarantor_address') ?: null,
                'branch_id' => $branchId,
                'is_active' => true,
            ]);

            return (int) $guarantor->id;
        }

        $selected = $request->input('default_guarantor_id');
        if ($selected === null || $selected === '') {
            return null;
        }

        return (int) $selected;
    }

    private function generateCustomerCode(string $prefix = 'CUST'): string
    {
        do {
            $code = $this->documentNumbers->next(
                DocumentNumberGenerator::TYPE_CUSTOMER,
                $prefix,
                now(),
                'ymd',
                4,
            );
        } while (Customer::where('customer_code', $code)->exists());

        return $code;
    }
}
