<?php

namespace App\Http\Controllers;

use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LedgerAccountController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(LedgerAccount::class, 'ledger_account');
    }

    public function index(Request $request): View|RedirectResponse
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'type' => (string) $request->input('type', ''),
            'active' => (string) $request->input('active', ''),
            'system' => (string) $request->input('system', ''),
        ];

        $drawerMode = (string) $request->query('drawer', '');
        $editId = $request->integer('edit');
        $editingLedgerAccount = null;

        if ($drawerMode === 'create') {
            $this->authorize('create', LedgerAccount::class);
        } elseif ($drawerMode === 'edit' && $editId) {
            $candidate = LedgerAccount::query()->find($editId);
            if (! $candidate) {
                return redirect()->route('ledger-accounts.index', $request->only(['q', 'type', 'active', 'system']));
            }
            $this->authorize('update', $candidate);
            $editingLedgerAccount = $candidate;
        }

        if ($request->old('_drawer') === 'edit') {
            $fallbackId = (int) $request->old('ledger_account_context_id', 0);
            if ($fallbackId && ! $editingLedgerAccount) {
                $fallback = LedgerAccount::query()->find($fallbackId);
                if ($fallback) {
                    $this->authorize('update', $fallback);
                    $editingLedgerAccount = $fallback;
                }
            }
        }

        $accounts = $this->filteredLedgerAccountsQuery($filters)
            ->orderBy('code')
            ->paginate($this->resolvePerPage($request, 30))
            ->withQueryString();

        $kpis = [
            'total' => LedgerAccount::count(),
            'system' => LedgerAccount::where('is_system', true)->count(),
            'custom' => LedgerAccount::where('is_system', false)->count(),
            'active' => LedgerAccount::where('is_active', true)->count(),
            'inactive' => LedgerAccount::where('is_active', false)->count(),
            'filtered' => $accounts->total(),
        ];

        $drawerAccount = $editingLedgerAccount ?? new LedgerAccount;

        return view('ledger-accounts.index', [
            'accounts' => $accounts,
            'filters' => $filters,
            'types' => LedgerAccount::types(),
            'kpis' => $kpis,
            'editingLedgerAccount' => $editingLedgerAccount,
            'drawerAccount' => $drawerAccount,
            'formTypes' => $this->formTypesFor($drawerAccount),
        ]);
    }

    /**
     * @param  Builder<LedgerAccount>  $query
     */
    private function applyLedgerFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['q'] !== '', function ($q) use ($filters): void {
                $q->where(function ($sub) use ($filters): void {
                    $sub->where('code', 'like', '%'.$filters['q'].'%')
                        ->orWhere('name', 'like', '%'.$filters['q'].'%');
                });
            })
            ->when($filters['type'] !== '', fn ($q) => $q->where('type', $filters['type']))
            ->when($filters['active'] !== '', fn ($q) => $q->where('is_active', $filters['active'] === '1'))
            ->when($filters['system'] !== '', fn ($q) => $q->where('is_system', $filters['system'] === '1'));
    }

    /**
     * @return Builder<LedgerAccount>
     */
    private function filteredLedgerAccountsQuery(array $filters): Builder
    {
        $query = LedgerAccount::query();
        $this->applyLedgerFilters($query, $filters);

        return $query;
    }

    /**
     * @return array<string, string>
     */
    private function formTypesFor(LedgerAccount $account): array
    {
        if (! $account->exists) {
            return collect(LedgerAccount::types())->only(['expense', 'income', 'liability', 'equity'])->all();
        }

        if ($account->is_system) {
            return LedgerAccount::types();
        }

        return collect(LedgerAccount::types())->only(['expense', 'income', 'liability', 'equity'])->all();
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('ledger-accounts.index', ['drawer' => 'create']);
    }

    public function store(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:40', 'unique:ledger_accounts,code', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['expense', 'income', 'liability', 'equity'])],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $code = $data['code'] ?? null;
        if (! $code) {
            do {
                $code = strtoupper(substr($data['type'], 0, 3)) . '-' . rand(1000, 9999);
            } while (LedgerAccount::where('code', $code)->exists());
        }

        $ledgerAccount = LedgerAccount::create([
            'code' => $code,
            'name' => $data['name'],
            'type' => $data['type'],
            'is_system' => false,
            'is_active' => $request->boolean('is_active', true),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'Account head created.',
                'account' => $ledgerAccount,
            ], 201);
        }

        $preserve = array_filter((array) $request->input('preserve', []));

        return redirect()->route('ledger-accounts.index', $preserve)->with('status', 'Account head created.');
    }

    public function edit(LedgerAccount $ledgerAccount): RedirectResponse
    {
        return redirect()->route('ledger-accounts.index', [
            'drawer' => 'edit',
            'edit' => $ledgerAccount->id,
        ]);
    }

    public function update(Request $request, LedgerAccount $ledgerAccount): RedirectResponse
    {
        if ($ledgerAccount->is_system) {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:150'],
                'is_active' => ['nullable', 'boolean'],
            ]);
            $ledgerAccount->update([
                'name' => $data['name'],
                'is_active' => $request->boolean('is_active', true),
            ]);

            $preserve = array_filter((array) $request->input('preserve', []));

            return redirect()->route('ledger-accounts.index', $preserve)->with('status', 'System account updated.');
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('ledger_accounts', 'code')->ignore($ledgerAccount->id)],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['expense', 'income', 'liability', 'equity'])],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $code = $data['code'] ?? null;
        if (! $code) {
            do {
                $code = strtoupper(substr($data['type'], 0, 3)) . '-' . rand(1000, 9999);
            } while (LedgerAccount::where('code', $code)->exists());
        }

        $ledgerAccount->update([
            'code' => $code,
            'name' => $data['name'],
            'type' => $data['type'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        $preserve = array_filter((array) $request->input('preserve', []));

        return redirect()->route('ledger-accounts.index', $preserve)->with('status', 'Account head updated.');
    }

    public function destroy(LedgerAccount $ledgerAccount): RedirectResponse
    {
        if ($ledgerAccount->wallet()->exists()) {
            return back()->with('status', 'Cannot delete a ledger account linked to a wallet.');
        }

        if (JournalLine::query()->where('ledger_account_id', $ledgerAccount->id)->exists()) {
            return back()->with('status', 'Cannot delete an account that already has journal lines.');
        }

        $ledgerAccount->delete();

        return redirect()->route('ledger-accounts.index')->with('status', 'Account head deleted.');
    }
}
