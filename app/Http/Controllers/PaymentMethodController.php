<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\Location;
use App\Models\PaymentMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentMethodController extends Controller
{
    use AppliesInventoryLocationContext;

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'location_id' => (string) $request->input('location_id', ''),
            'type' => (string) $request->input('type', ''),
            'active' => (string) $request->input('active', ''),
        ];

        $drawerMode = (string) $request->query('drawer', '');
        $editId = $request->integer('edit');
        $editingPaymentMethod = null;

        if ($drawerMode === 'create') {
            $this->authorize('create', PaymentMethod::class);
        } elseif ($drawerMode === 'edit' && $editId) {
            $candidate = PaymentMethod::query()->find($editId);
            if (! $candidate) {
                return redirect()->route('payment-methods.index', $request->except(['drawer', 'edit']));
            }
            $this->authorize('update', $candidate);
            $candidate->load(['walletMaps.location', 'walletMaps.wallet']);
            $editingPaymentMethod = $candidate;
        }

        $ctxId = $this->contextLocationId();

        $methods = PaymentMethod::query()
            ->with(['location', 'walletMaps.location', 'walletMaps.wallet'])
            ->when($filters['location_id'] === '' && $ctxId !== null, function ($query) use ($ctxId): void {
                $query->where(function ($sub) use ($ctxId): void {
                    $sub->whereNull('location_id')->orWhere('location_id', $ctxId);
                });
            })
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $q = $filters['q'];
                $query->where(function ($subQuery) use ($q): void {
                    $subQuery->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('account_number', 'like', "%{$q}%")
                        ->orWhere('mobile_number', 'like', "%{$q}%");
                });
            })
            ->when($filters['location_id'] !== '', function ($query) use ($filters): void {
                if ($filters['location_id'] === 'global') {
                    $query->whereNull('location_id');

                    return;
                }

                $query->where('location_id', (int) $filters['location_id']);
            })
            ->when($filters['type'] !== '', fn ($query) => $query->where('type', $filters['type']))
            ->when($filters['active'] !== '', fn ($query) => $query->where('is_active', $filters['active'] === '1'))
            ->latest()
            ->paginate($this->resolvePerPage($request, 20))
            ->withQueryString();

        return view('payment-methods.index', [
            'methods' => $methods,
            'locations' => Location::where('is_active', true)->orderBy('name')->get(),
            'filters' => $filters,
            'types' => $this->types(),
            'editingPaymentMethod' => $editingPaymentMethod,
        ]);
    }

    public function create(): RedirectResponse
    {
        $this->authorize('create', PaymentMethod::class);

        return redirect()->route('payment-methods.index', ['drawer' => 'create']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);
        $data['created_by'] = auth()->id();

        PaymentMethod::create($data);

        return redirect()->route('payment-methods.index')->with('status', 'Payment method created successfully.');
    }

    public function edit(PaymentMethod $paymentMethod): RedirectResponse
    {
        $this->authorize('update', $paymentMethod);

        return redirect()->route('payment-methods.index', [
            'drawer' => 'edit',
            'edit' => $paymentMethod->id,
        ]);
    }

    public function update(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $data = $this->validatedData($request, $paymentMethod->id);
        $paymentMethod->update($data);

        return redirect()->route('payment-methods.index')->with('status', 'Payment method updated successfully.');
    }

    public function destroy(PaymentMethod $paymentMethod): RedirectResponse
    {
        if ($paymentMethod->payments()->exists()) {
            return back()->with('status', 'Cannot delete payment method that already has payments. You can deactivate it instead.');
        }

        $paymentMethod->delete();

        return redirect()->route('payment-methods.index')->with('status', 'Payment method deleted.');
    }

    private function validatedData(Request $request, ?int $methodId = null): array
    {
        $data = $request->validate([
            'location_id' => ['nullable', 'exists:locations,id'],
            'code' => ['required', 'string', 'max:40', 'unique:payment_methods,code'.($methodId ? ','.$methodId : '')],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:cash,bank,card_terminal,mobile_banking'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:80'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_branch' => ['nullable', 'string', 'max:120'],
            'routing_number' => ['nullable', 'string', 'max:60'],
            'swift_code' => ['nullable', 'string', 'max:40'],
            'terminal_provider' => ['nullable', 'string', 'max:120'],
            'terminal_id' => ['nullable', 'string', 'max:80'],
            'merchant_id' => ['nullable', 'string', 'max:80'],
            'mobile_provider' => ['nullable', 'string', 'max:120'],
            'mobile_number' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function types(): array
    {
        return [
            'cash' => 'Cash Account',
            'bank' => 'Bank Account',
            'card_terminal' => 'Card Terminal',
            'mobile_banking' => 'Mobile Banking',
        ];
    }
}
