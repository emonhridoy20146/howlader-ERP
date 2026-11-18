<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodWalletMap;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PaymentMethodWalletMapController extends Controller
{
    use AppliesInventoryLocationContext;

    public function __construct()
    {
        $this->authorizeResource(PaymentMethodWalletMap::class, 'payment_method_wallet_map');
    }

    public function index(Request $request): View
    {
        $filters = [
            'location_id' => (string) $request->input('location_id', ''),
            'payment_method_id' => (string) $request->input('payment_method_id', ''),
        ];
        $ctxId = $this->contextLocationId();

        $maps = PaymentMethodWalletMap::query()
            ->with(['location', 'paymentMethod', 'wallet'])
            ->when($filters['location_id'] === '' && $ctxId !== null, fn ($q) => $q->where('location_id', $ctxId))
            ->when($filters['location_id'] !== '', fn ($q) => $q->where('location_id', (int) $filters['location_id']))
            ->when($filters['payment_method_id'] !== '', fn ($q) => $q->where('payment_method_id', (int) $filters['payment_method_id']))
            ->whereIn('location_id', auth()->user()->accessibleLocationIds())
            ->orderBy('location_id')
            ->orderBy('payment_method_id')
            ->paginate($this->resolvePerPage($request, 30))
            ->withQueryString();

        $locations = Location::query()
            ->where('is_active', true)
            ->whereIn('id', auth()->user()->accessibleLocationIds())
            ->orderBy('name')
            ->get();

        $paymentMethodsForFilter = PaymentMethod::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $drawerMode = (string) $request->query('drawer', '');
        $editId = $request->integer('edit');
        $editingMap = null;

        if ($drawerMode === 'create') {
            $this->authorize('create', PaymentMethodWalletMap::class);
        } elseif ($drawerMode === 'edit' && $editId) {
            $candidate = PaymentMethodWalletMap::query()
                ->whereIn('location_id', $request->user()->accessibleLocationIds())
                ->find($editId);
            if (! $candidate) {
                return redirect()->route('payment-method-wallet-maps.index', $request->except(['drawer', 'edit']));
            }
            $this->authorize('update', $candidate);
            $candidate->load(['location', 'paymentMethod', 'wallet']);
            $editingMap = $candidate;
        }

        $mapForForm = $editingMap ?? new PaymentMethodWalletMap;
        if (! $editingMap) {
            $mapForForm->fill([
                'location_id' => old('location_id', $request->query('location_id') ? (int) $request->query('location_id') : null),
                'payment_method_id' => old('payment_method_id', $request->query('payment_method_id') ? (int) $request->query('payment_method_id') : null),
                'wallet_id' => old('wallet_id', $request->query('wallet_id') ? (int) $request->query('wallet_id') : null),
            ]);
        }

        $optLocationId = (int) old('location_id', $mapForForm->location_id ?? 0);
        $includePaymentMethodId = $mapForForm->payment_method_id ? (int) $mapForForm->payment_method_id : null;
        $includeWalletId = $mapForForm->wallet_id ? (int) $mapForForm->wallet_id : null;
        $mapMethodsOptions = [];
        $mapWalletsOptions = [];
        if ($optLocationId > 0 && in_array($optLocationId, $request->user()->accessibleLocationIds(), true)) {
            $mapMethodsOptions = $this->methodOptionsForBranch($optLocationId, $includePaymentMethodId);
            $mapWalletsOptions = $this->walletOptionsForBranch($optLocationId, $includeWalletId);
        }

        return view('payment-method-wallet-maps.index', [
            'maps' => $maps,
            'filters' => $filters,
            'locations' => $locations,
            'paymentMethodsForFilter' => $paymentMethodsForFilter,
            'editingMap' => $editingMap,
            'mapForForm' => $mapForForm,
            'mapMethodsOptions' => $mapMethodsOptions,
            'mapWalletsOptions' => $mapWalletsOptions,
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $this->authorize('create', PaymentMethodWalletMap::class);

        $query = array_merge(
            ['drawer' => 'create'],
            $request->only(['location_id', 'payment_method_id', 'wallet_id'])
        );

        return redirect()->route('payment-method-wallet-maps.index', array_filter($query, fn ($v) => $v !== null && $v !== ''));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        PaymentMethodWalletMap::create($data);

        return redirect()->route('payment-method-wallet-maps.index')->with('status', 'Mapping saved.');
    }

    public function edit(PaymentMethodWalletMap $paymentMethodWalletMap): RedirectResponse
    {
        $this->authorize('update', $paymentMethodWalletMap);

        return redirect()->route('payment-method-wallet-maps.index', [
            'drawer' => 'edit',
            'edit' => $paymentMethodWalletMap->id,
        ]);
    }

    public function update(Request $request, PaymentMethodWalletMap $paymentMethodWalletMap): RedirectResponse
    {
        $data = $this->validated($request, $paymentMethodWalletMap->id);
        $paymentMethodWalletMap->update($data);

        return redirect()->route('payment-method-wallet-maps.index')->with('status', 'Mapping updated.');
    }

    public function destroy(PaymentMethodWalletMap $paymentMethodWalletMap): RedirectResponse
    {
        $paymentMethodWalletMap->delete();

        return redirect()->route('payment-method-wallet-maps.index')->with('status', 'Mapping removed.');
    }

    /**
     * JSON for dependent dropdowns on the create form.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaymentMethodWalletMap::class);

        $locationId = (int) $request->query('location_id', 0);
        if (! in_array($locationId, auth()->user()->accessibleLocationIds(), true)) {
            abort(403);
        }

        $includePm = $request->filled('include_payment_method_id') ? $request->integer('include_payment_method_id') : null;
        $includeWallet = $request->filled('include_wallet_id') ? $request->integer('include_wallet_id') : null;

        return response()->json([
            'payment_methods' => $this->paymentMethodsForBranch($locationId, $includePm)->map(fn (PaymentMethod $m) => [
                'id' => $m->id,
                'label' => $m->code.' — '.$m->name,
            ])->values(),
            'wallets' => $this->walletsForBranch($locationId, $includeWallet)->map(fn (Wallet $w) => [
                'id' => $w->id,
                'label' => $w->code.' — '.$w->name,
            ])->values(),
        ]);
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function methodOptionsForBranch(int $locationId, ?int $includePaymentMethodId = null): array
    {
        return $this->paymentMethodsForBranch($locationId, $includePaymentMethodId)->map(fn (PaymentMethod $m) => [
            'id' => $m->id,
            'label' => $m->code.' — '.$m->name,
        ])->values()->all();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function walletOptionsForBranch(int $locationId, ?int $includeWalletId = null): array
    {
        return $this->walletsForBranch($locationId, $includeWalletId)->map(fn (Wallet $w) => [
            'id' => $w->id,
            'label' => $w->code.' — '.$w->name,
        ])->values()->all();
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    private function paymentMethodsForBranch(int $locationId, ?int $includePaymentMethodId = null)
    {
        $methods = PaymentMethod::query()
            ->where('is_active', true)
            ->where(function ($q) use ($locationId): void {
                $q->whereNull('location_id')->orWhere('location_id', $locationId);
            })
            ->orderBy('name')
            ->get();

        if ($includePaymentMethodId !== null && ! $methods->contains('id', $includePaymentMethodId)) {
            $extra = PaymentMethod::query()->find($includePaymentMethodId);
            if ($extra !== null) {
                $branchOk = $extra->location_id === null || (int) $extra->location_id === $locationId;
                if ($branchOk) {
                    $methods->push($extra);
                }
            }
        }

        return $methods->sortBy('name')->values();
    }

    /**
     * @return Collection<int, Wallet>
     */
    private function walletsForBranch(int $locationId, ?int $includeWalletId = null)
    {
        $wallets = Wallet::query()
            ->where('is_active', true)
            ->whereAppliesToLocation($locationId)
            ->orderBy('code')
            ->get();

        if ($includeWalletId !== null && ! $wallets->contains('id', $includeWalletId)) {
            $extra = Wallet::query()->whereKey($includeWalletId)->with('locations')->first();
            if ($extra !== null && $extra->appliesToLocation($locationId)) {
                $wallets->push($extra);
            }
        }

        return $wallets->sortBy('code')->values();
    }

    private function validated(Request $request, ?int $mapId = null): array
    {
        $uniqueRule = Rule::unique('payment_method_wallet', 'payment_method_id')
            ->where(fn ($q) => $q->where('location_id', (int) $request->input('location_id')));
        if ($mapId !== null) {
            $uniqueRule->ignore($mapId);
        }

        $data = $request->validate([
            'location_id' => ['required', 'integer', Rule::in(auth()->user()->accessibleLocationIds())],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id', $uniqueRule],
            'wallet_id' => ['required', 'integer', 'exists:wallets,id'],
        ]);

        $locationId = (int) $data['location_id'];
        $methods = $this->paymentMethodsForBranch($locationId, (int) $data['payment_method_id']);
        if (! $methods->pluck('id')->contains((int) $data['payment_method_id'])) {
            throw ValidationException::withMessages([
                'payment_method_id' => 'This payment method is not available for the selected branch.',
            ]);
        }

        $wallet = Wallet::query()->whereKey((int) $data['wallet_id'])->with('locations')->firstOrFail();
        if (! $wallet->appliesToLocation($locationId)) {
            throw ValidationException::withMessages([
                'wallet_id' => 'This wallet is not available for the selected branch.',
            ]);
        }

        return $data;
    }
}
