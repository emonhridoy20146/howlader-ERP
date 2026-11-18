<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WalletController extends Controller
{
    use AppliesInventoryLocationContext;

    public function __construct()
    {
        $this->authorizeResource(Wallet::class, 'wallet');
    }

    public function index(Request $request): View
    {
        $filters = [
            'location_id' => (string) $request->input('location_id', ''),
            'q' => trim((string) $request->input('q', '')),
        ];
        $ctxId = $this->contextLocationId();

        $wallets = Wallet::query()
            ->with(['locations', 'ledgerAccount'])
            ->forUserLocations($request->user())
            ->when($filters['location_id'] === '' && $ctxId !== null, fn ($q) => $q->whereAppliesToLocation((int) $ctxId))
            ->when($filters['location_id'] !== '', fn ($q) => $q->whereAppliesToLocation((int) $filters['location_id']))
            ->when($filters['q'] !== '', function ($q) use ($filters): void {
                $q->where(function ($sub) use ($filters): void {
                    $sub->where('code', 'like', '%'.$filters['q'].'%')
                        ->orWhere('name', 'like', '%'.$filters['q'].'%');
                });
            })
            ->orderBy('code')
            ->paginate($this->resolvePerPage($request, 20))
            ->withQueryString();

        $ledgerIds = $wallets->getCollection()->pluck('ledger_account_id')->unique()->filter()->all();
        $balances = [];
        if ($ledgerIds !== []) {
            $balances = JournalLine::query()
                ->whereIn('ledger_account_id', $ledgerIds)
                ->groupBy('ledger_account_id')
                ->selectRaw('ledger_account_id, COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as bal')
                ->pluck('bal', 'ledger_account_id');
        }

        $locations = Location::query()
            ->where('is_active', true)
            ->whereIn('id', auth()->user()->accessibleLocationIds())
            ->orderBy('name')
            ->get();

        $user = $request->user();

        return view('wallets.index', [
            'wallets' => $wallets,
            'filters' => $filters,
            'locations' => $locations,
            'balances' => $balances,
            'balanceOnly' => $user !== null
                && $user->can('viewBalance', Wallet::class)
                && ! $user->hasPermission('wallet.view'),
        ]);
    }

    public function create(): View
    {
        $locations = Location::query()
            ->where('is_active', true)
            ->whereIn('id', auth()->user()->accessibleLocationIds())
            ->orderBy('name')
            ->get();

        return view('wallets.create', [
            'wallet' => new Wallet,
            'locations' => $locations,
            'kinds' => Wallet::kinds(),
        ]);
    }

    public function show(Request $request, Wallet $wallet): View
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $wallet->load([
            'locations',
            'ledgerAccount',
            'paymentMethodMaps.location',
            'paymentMethodMaps.paymentMethod',
        ]);

        $paymentMaps = $wallet->paymentMethodMaps
            ->sortBy(fn ($map) => sprintf(
                '%s-%s',
                $map->location?->code ?? '',
                $map->paymentMethod?->name ?? '',
            ))
            ->values();

        $filters = [
            'location_id' => (string) $request->input('location_id', ''),
            'source' => (string) $request->input('source', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
        ];

        $locations = Location::query()
            ->where('is_active', true)
            ->whereIn('id', $request->user()->accessibleLocationIds())
            ->orderBy('name')
            ->get();

        $ledgerId = $wallet->ledger_account_id;
        $transactionLines = null;
        $transactionTotals = [
            'line_count' => 0,
            'total_debit' => 0.0,
            'total_credit' => 0.0,
        ];
        $openingBalance = null;
        $closingBalance = null;

        if ($ledgerId !== null) {
            $baseLines = $this->walletLedgerLineBaseQuery($wallet, $request, $filters);

            if ($filters['date_from'] !== '') {
                $openingQuery = (clone $baseLines)->whereDate('je.entry_date', '<', $filters['date_from']);
                $openingBalance = $this->walletLedgerNetFromQuery($openingQuery);
            }

            $filteredLines = (clone $baseLines)
                ->when($filters['date_from'] !== '', fn (Builder $q) => $q->whereDate('je.entry_date', '>=', $filters['date_from']))
                ->when($filters['date_to'] !== '', fn (Builder $q) => $q->whereDate('je.entry_date', '<=', $filters['date_to']))
                ->when($filters['source'] !== '', fn (Builder $q) => $q->where('je.source', $filters['source']));

            $transactionTotals = $this->walletLedgerTotalsFromQuery(clone $filteredLines);

            $periodNet = round($transactionTotals['total_debit'] - $transactionTotals['total_credit'], 2);
            if ($openingBalance !== null) {
                $closingBalance = round($openingBalance + $periodNet, 2);
            }

            $transactionLines = (clone $filteredLines)
                ->with(['journalEntry.branch', 'journalEntry.creator'])
                ->orderByDesc('je.entry_date')
                ->orderByDesc('je.id')
                ->orderByDesc('journal_lines.id')
                ->paginate($this->resolvePerPage($request, 25))
                ->withQueryString();
        }

        return view('wallets.show', [
            'wallet' => $wallet,
            'balance' => $wallet->currentBalance(),
            'kindLabel' => Wallet::kinds()[$wallet->kind] ?? $wallet->kind,
            'paymentMaps' => $paymentMaps,
            'filters' => $filters,
            'locations' => $locations,
            'sources' => JournalEntry::sources(),
            'transactionLines' => $transactionLines,
            'transactionTotals' => $transactionTotals,
            'openingBalance' => $openingBalance,
            'closingBalance' => $closingBalance,
        ]);
    }

    /**
     * Debit − credit for wallet lines matching the query (consumes clone; clears prior column select for aggregates).
     *
     * @param  Builder<JournalLine>  $ledgerLineQuery
     */
    private function walletLedgerNetFromQuery(Builder $ledgerLineQuery): float
    {
        $ledgerLineQuery->getQuery()->columns = null;
        $ledgerLineQuery->reorder();

        $raw = $ledgerLineQuery
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) as bal')
            ->value('bal');

        return round((float) ($raw ?? 0), 2);
    }

    /**
     * @param  Builder<JournalLine>  $ledgerLineQuery
     * @return array{line_count: int, total_debit: float, total_credit: float}
     */
    private function walletLedgerTotalsFromQuery(Builder $ledgerLineQuery): array
    {
        $ledgerLineQuery->getQuery()->columns = null;
        $ledgerLineQuery->reorder();

        /** @var \stdClass|null $row */
        $row = $ledgerLineQuery
            ->selectRaw('COUNT(*) as line_count, COALESCE(SUM(journal_lines.debit), 0) as total_debit, COALESCE(SUM(journal_lines.credit), 0) as total_credit')
            ->first();

        if (! $row) {
            return [
                'line_count' => 0,
                'total_debit' => 0.0,
                'total_credit' => 0.0,
            ];
        }

        return [
            'line_count' => (int) $row->line_count,
            'total_debit' => round((float) $row->total_debit, 2),
            'total_credit' => round((float) $row->total_credit, 2),
        ];
    }

    /**
     * Journal lines on this wallet’s ledger, scoped to branches the user may see (same rules as the journal index).
     *
     * @return Builder<JournalLine>
     */
    private function walletLedgerLineBaseQuery(Wallet $wallet, Request $request, array $filters): Builder
    {
        $accessible = $request->user()->accessibleLocationIds();
        $ctxId = $this->contextLocationId();
        $ledgerId = $wallet->ledger_account_id;

        return JournalLine::query()
            ->select('journal_lines.*')
            ->where('journal_lines.ledger_account_id', $ledgerId)
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('je.branch_id', $accessible)
            ->when(
                $filters['location_id'] !== '',
                fn (Builder $q) => $q->where('je.branch_id', (int) $filters['location_id']),
            )
            ->when(
                $filters['location_id'] === '' && $ctxId !== null,
                fn (Builder $q) => $q->where('je.branch_id', $ctxId),
            );
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['code' => strtoupper((string) $request->input('code', ''))]);

        $accessible = auth()->user()->accessibleLocationIds();

        $data = $request->validate([
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer', Rule::in($accessible)],
            'code' => [
                'required',
                'string',
                'max:30',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('wallets', 'code'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', Rule::in(array_keys(Wallet::kinds()))],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:80'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $ledgerCode = 'W-'.$data['code'];
        if (strlen($ledgerCode) > 40) {
            return back()->withErrors(['code' => 'Code is too long for the ledger account code.'])->withInput();
        }
        if (LedgerAccount::query()->where('code', $ledgerCode)->exists()) {
            return back()->withErrors(['code' => 'Ledger code collision; choose a different wallet code.'])->withInput();
        }

        $ledger = LedgerAccount::create([
            'code' => $ledgerCode,
            'name' => $data['name'],
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = Wallet::create([
            'ledger_account_id' => $ledger->id,
            'code' => $data['code'],
            'name' => $data['name'],
            'kind' => $data['kind'],
            'bank_name' => $data['bank_name'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'notes' => $data['notes'] ?? null,
        ]);

        $locationIds = array_values(array_unique(array_map('intval', $data['location_ids'] ?? [])));
        $locationIds = array_values(array_intersect($locationIds, $accessible));
        if ($locationIds !== []) {
            $wallet->locations()->sync($locationIds);
        }

        return redirect()->route('wallets.index')->with('status', 'Wallet created.');
    }

    public function edit(Wallet $wallet): View
    {
        $locations = Location::query()
            ->where('is_active', true)
            ->whereIn('id', auth()->user()->accessibleLocationIds())
            ->orderBy('name')
            ->get();

        $wallet->load('locations');

        return view('wallets.edit', [
            'wallet' => $wallet,
            'locations' => $locations,
            'kinds' => Wallet::kinds(),
        ]);
    }

    public function update(Request $request, Wallet $wallet): RedirectResponse
    {
        $accessible = auth()->user()->accessibleLocationIds();

        $data = $request->validate([
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer', Rule::in($accessible)],
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', Rule::in(array_keys(Wallet::kinds()))],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:80'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $wallet->update([
            'name' => $data['name'],
            'kind' => $data['kind'],
            'bank_name' => $data['bank_name'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'notes' => $data['notes'] ?? null,
        ]);

        $wallet->ledgerAccount()->update(['name' => $data['name']]);

        $locationIds = array_values(array_unique(array_map('intval', $data['location_ids'] ?? [])));
        $locationIds = array_values(array_intersect($locationIds, $accessible));
        $wallet->locations()->sync($locationIds);

        return redirect()->route('wallets.index')->with('status', 'Wallet updated.');
    }

    public function destroy(Wallet $wallet): RedirectResponse
    {
        if (JournalLine::query()->where('ledger_account_id', $wallet->ledger_account_id)->exists()) {
            return back()->with('status', 'Cannot delete a wallet that already has journal activity.');
        }

        if ($wallet->paymentMethodMaps()->exists()) {
            return back()->with('status', 'Remove payment-method assignments before deleting this wallet.');
        }

        $ledgerId = $wallet->ledger_account_id;
        $wallet->delete();
        LedgerAccount::query()->whereKey($ledgerId)->where('is_system', false)->delete();

        return redirect()->route('wallets.index')->with('status', 'Wallet deleted.');
    }
}
