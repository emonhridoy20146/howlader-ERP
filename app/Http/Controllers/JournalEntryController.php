<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesInvoice;
use App\Models\Wallet;
use App\Exceptions\InvalidStateException;
use App\Services\JournalService;
use App\Services\PaymentService;
use App\Support\OwnerCapitalWallet;
use App\Support\CompanyPad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class JournalEntryController extends Controller
{
    use AppliesInventoryLocationContext;

    public function __construct(
        private readonly JournalService $journalService,
    ) {
        $this->authorizeResource(JournalEntry::class, 'journal_entry');
    }

    public function index(Request $request): View
    {
        [
            'filters' => $filters,
            'walletLedgerId' => $walletLedgerId,
            'entriesBase' => $entriesBase,
            'walletsForFilter' => $walletsForFilter,
        ] = $this->journalFilterContext($request);

        $entriesQuery = (clone $entriesBase)
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        if ($walletLedgerId !== null) {
            $entriesQuery
                ->withSum(['lines as lines_debit_sum' => fn ($q) => $q->where('ledger_account_id', $walletLedgerId)], 'debit')
                ->withSum(['lines as lines_credit_sum' => fn ($q) => $q->where('ledger_account_id', $walletLedgerId)], 'credit');
        } else {
            $entriesQuery
                ->withSum('lines as lines_debit_sum', 'debit')
                ->withSum('lines as lines_credit_sum', 'credit');
        }

        $entries = $entriesQuery
            ->paginate($this->resolvePerPage($request, 25))
            ->withQueryString();

        JournalEntry::loadLinkedCustomerReferences($entries->getCollection());

        $locations = Location::query()
            ->where('is_active', true)
            ->whereIn('id', auth()->user()->accessibleLocationIds())
            ->orderBy('name')
            ->get();

        $journalTotals = $this->journalIndexTotals($entriesBase, $walletLedgerId);

        return view('journal-entries.index', [
            'entries' => $entries,
            'filters' => $filters,
            'locations' => $locations,
            'sources' => JournalEntry::sources(),
            'walletsForFilter' => $walletsForFilter,
            'journalTotals' => $journalTotals,
        ]);
    }

    /**
     * @return array{
     *     filters: array{location_id: string, wallet_id: string, source: string, date_from: string, date_to: string},
     *     walletLedgerId: int|null,
     *     entriesBase: Builder,
     *     walletsForFilter: Collection<int, Wallet>,
     * }
     */
    private function journalFilterContext(Request $request): array
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $walletsForFilter = Wallet::query()
            ->where('is_active', true)
            ->with('locations')
            ->forUserLocations($request->user())
            ->orderBy('code')
            ->get();

        $walletIdInput = $request->input('wallet_id');
        $walletIdFilter = '';
        if ($walletIdInput !== null && $walletIdInput !== '') {
            $wid = (int) $walletIdInput;
            if ($walletsForFilter->contains('id', $wid)) {
                $walletIdFilter = (string) $wid;
            }
        }

        $filters = [
            'location_id' => (string) $request->input('location_id', ''),
            'wallet_id' => $walletIdFilter,
            'source' => (string) $request->input('source', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
        ];

        $ctxId = $this->contextLocationId();

        $walletLedgerId = null;
        if ($filters['wallet_id'] !== '') {
            $walletLedgerId = Wallet::query()->whereKey((int) $filters['wallet_id'])->value('ledger_account_id');
        }

        $entriesBase = JournalEntry::query()
            ->with('branch')
            ->when($filters['location_id'] === '' && $ctxId !== null, fn ($q) => $q->where('branch_id', $ctxId))
            ->when($filters['location_id'] !== '', fn ($q) => $q->where('branch_id', (int) $filters['location_id']))
            ->when($filters['source'] !== '', fn ($q) => $q->where('source', $filters['source']))
            ->when($filters['date_from'] !== '', fn ($q) => $q->whereDate('entry_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($q) => $q->whereDate('entry_date', '<=', $filters['date_to']))
            ->when(
                $walletLedgerId !== null,
                fn ($q) => $q->whereHas('lines', fn ($q2) => $q2->where('ledger_account_id', $walletLedgerId)),
            )
            ->whereIn('branch_id', $request->user()->accessibleLocationIds());

        return [
            'filters' => $filters,
            'walletLedgerId' => $walletLedgerId,
            'entriesBase' => $entriesBase,
            'walletsForFilter' => $walletsForFilter,
        ];
    }

    /**
     * @param  Collection<int, Wallet>  $walletsForFilter
     * @return array{period: string, branch: string, source: string, wallet: string}
     */
    private function journalReportFilterLabels(Request $request, array $filters, $walletsForFilter): array
    {
        $ctxId = $this->contextLocationId();
        $accessibleIds = $request->user()->accessibleLocationIds();

        if ($filters['location_id'] !== '') {
            $loc = Location::query()
                ->whereKey((int) $filters['location_id'])
                ->whereIn('id', $accessibleIds)
                ->first();
            $branchLabel = $loc ? (string) $loc->name : '—';
        } elseif ($ctxId !== null && in_array($ctxId, $accessibleIds, true)) {
            $ctxName = Location::query()->whereKey($ctxId)->value('name');
            $branchLabel = ($ctxName !== null && $ctxName !== '' ? (string) $ctxName : 'Current branch').' (context)';
        } else {
            $branchLabel = 'All accessible branches';
        }

        $df = $filters['date_from'];
        $dt = $filters['date_to'];
        if ($df === '' && $dt === '') {
            $period = 'All dates';
        } elseif ($df !== '' && $dt !== '') {
            $period = $df.' → '.$dt;
        } elseif ($df !== '') {
            $period = 'From '.$df;
        } else {
            $period = 'Until '.$dt;
        }

        $sources = JournalEntry::sources();
        $sourceLabel = $filters['source'] === '' ? 'Any' : ($sources[$filters['source']] ?? $filters['source']);

        $walletLabel = '';
        if ($filters['wallet_id'] !== '') {
            $w = $walletsForFilter->firstWhere('id', (int) $filters['wallet_id']);
            $walletLabel = $w ? $w->code.' — '.$w->name : '—';
        }

        return [
            'period' => $period,
            'branch' => $branchLabel,
            'source' => $sourceLabel,
            'wallet' => $walletLabel,
        ];
    }

    /**
     * @return array{total_debit: float, total_credit: float, entry_count: int}
     */
    private function journalIndexTotals($entriesBase, ?int $walletLedgerId): array
    {
        $entryIdsQuery = $entriesBase->clone()->select('journal_entries.id');

        $agg = JournalLine::withoutGlobalScope('posted')
            ->whereIn('journal_entry_id', $entryIdsQuery)
            ->when($walletLedgerId !== null, fn ($q) => $q->where('ledger_account_id', $walletLedgerId))
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        return [
            'total_debit' => (float) ($agg->total_debit ?? 0),
            'total_credit' => (float) ($agg->total_credit ?? 0),
            'entry_count' => (int) $entriesBase->clone()->count(),
        ];
    }

    public function show(JournalEntry $journalEntry): View
    {
        $journalEntry->load([
            'lines.ledgerAccount',
            'branch',
            'creator',
        ]);

        $journalEntry->loadMorph('reference', [
            Payment::class => ['customer', 'paymentAllocations.salesInvoice'],
            PaymentAllocation::class => ['payment.customer', 'salesInvoice.customer'],
            SalesInvoice::class => ['customer'],
        ]);

        return view('journal-entries.show', [
            'entry' => $journalEntry,
            'linkedPaymentRemoval' => $journalEntry->linkedPaymentRemovalTarget(),
        ]);
    }

    public function detailedReport(Request $request): View
    {
        $this->authorize('viewAny', JournalEntry::class);

        [
            'filters' => $filters,
            'walletLedgerId' => $walletLedgerId,
            'entriesBase' => $entriesBase,
            'walletsForFilter' => $walletsForFilter,
        ] = $this->journalFilterContext($request);

        $entryIdsQuery = $entriesBase->clone()->select('journal_entries.id');

        $lines = JournalLine::withoutGlobalScope('posted')
            ->whereIn('journal_entry_id', $entryIdsQuery)
            ->when($walletLedgerId !== null, fn ($q) => $q->where('ledger_account_id', $walletLedgerId))
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')
            ->orderByDesc('je.entry_date')
            ->orderByDesc('je.id')
            ->orderBy('journal_lines.id')
            ->select('journal_lines.*')
            ->with(['journalEntry.branch', 'ledgerAccount'])
            ->get();

        $totals = [
            'line_count' => $lines->count(),
            'total_debit' => (float) $lines->sum(fn (JournalLine $l) => (float) $l->debit),
            'total_credit' => (float) $lines->sum(fn (JournalLine $l) => (float) $l->credit),
        ];

        return view('journal-entries.detailed-report-print', [
            'companyPad' => CompanyPad::settings(),
            'filterLabels' => $this->journalReportFilterLabels($request, $filters, $walletsForFilter),
            'lines' => $lines,
            'totals' => $totals,
            'printedAt' => now(),
        ]);
    }

    public function accountSummaryReport(Request $request): View
    {
        $this->authorize('viewAny', JournalEntry::class);

        [
            'filters' => $filters,
            'walletLedgerId' => $walletLedgerId,
            'entriesBase' => $entriesBase,
            'walletsForFilter' => $walletsForFilter,
        ] = $this->journalFilterContext($request);

        $entryIdsQuery = $entriesBase->clone()->select('journal_entries.id');

        $rows = JournalLine::withoutGlobalScope('posted')
            ->whereIn('journal_entry_id', $entryIdsQuery)
            ->when($walletLedgerId !== null, fn ($q) => $q->where('ledger_account_id', $walletLedgerId))
            ->join('ledger_accounts as la', 'la.id', '=', 'journal_lines.ledger_account_id')
            ->selectRaw('journal_lines.ledger_account_id, la.code as account_code, la.name as account_name, COALESCE(SUM(journal_lines.debit), 0) as total_debit, COALESCE(SUM(journal_lines.credit), 0) as total_credit')
            ->groupBy('journal_lines.ledger_account_id', 'la.code', 'la.name')
            ->orderBy('la.code')
            ->get();

        $totals = [
            'account_count' => $rows->count(),
            'total_debit' => (float) $rows->sum(fn ($r) => (float) $r->total_debit),
            'total_credit' => (float) $rows->sum(fn ($r) => (float) $r->total_credit),
        ];

        return view('journal-entries.account-summary-report-print', [
            'companyPad' => CompanyPad::settings(),
            'filterLabels' => $this->journalReportFilterLabels($request, $filters, $walletsForFilter),
            'rows' => $rows,
            'totals' => $totals,
            'printedAt' => now(),
        ]);
    }

    public function create(Request $request): View
    {
        if ($this->allowedJournalPostKinds() === []) {
            abort(403);
        }

        $locations = Location::query()
            ->where('is_active', true)
            ->whereIn('id', auth()->user()->accessibleLocationIds())
            ->orderBy('name')
            ->get();

        $expenseAccounts = LedgerAccount::query()
            ->where('type', 'expense')
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $incomeAccounts = LedgerAccount::query()
            ->where('type', 'income')
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $accessible = auth()->user()->accessibleLocationIds();
        $wallets = Wallet::query()
            ->where('is_active', true)
            ->with('locations')
            ->forUserLocations(auth()->user())
            ->orderBy('code')
            ->get();

        $user = auth()->user();
        $walletsGrouped = [];
        foreach ($accessible as $locId) {
            $walletsGrouped[(string) $locId] = $wallets
                ->filter(fn (Wallet $w) => $w->appliesToLocation((int) $locId))
                ->map(fn (Wallet $w) => [
                    'id' => $w->id,
                    'label' => $w->code.' — '.$w->name,
                    'allow_expense_from' => OwnerCapitalWallet::userCanExpenseFrom($user, $w),
                    'allow_opening_balance' => OwnerCapitalWallet::userCanPostOpeningBalance($user, $w),
                    'allow_transfer_from' => OwnerCapitalWallet::userCanTransferFrom($user, $w),
                ])
                ->values()
                ->all();
        }

        $ctxId = $this->contextLocationId();
        $journalDefaultBranchId = (string) ($locations->first()?->id ?? '');
        if ($ctxId !== null && in_array($ctxId, $accessible, true)) {
            $journalDefaultBranchId = (string) $ctxId;
        }

        $prefillWalletId = null;
        $prefillWalletSummary = null;
        $walletIdInput = $request->query('wallet_id');
        if ($walletIdInput !== null && $walletIdInput !== '') {
            $wid = (int) $walletIdInput;
            $prefillWallet = $wallets->firstWhere('id', $wid);
            if ($prefillWallet !== null) {
                $prefillWalletId = $wid;
                $prefillWalletSummary = $prefillWallet->code.' — '.$prefillWallet->name;
                if ($ctxId !== null && in_array($ctxId, $accessible, true) && $prefillWallet->appliesToLocation($ctxId)) {
                    $journalDefaultBranchId = (string) $ctxId;
                } else {
                    foreach ($locations as $loc) {
                        if ($prefillWallet->appliesToLocation((int) $loc->id)) {
                            $journalDefaultBranchId = (string) $loc->id;

                            break;
                        }
                    }
                }
            }
        }

        return view('journal-entries.create', [
            'locations' => $locations,
            'expenseAccounts' => $expenseAccounts,
            'incomeAccounts' => $incomeAccounts,
            'sources' => JournalEntry::sources(),
            'walletsGrouped' => $walletsGrouped,
            'journalDefaultBranchId' => $journalDefaultBranchId,
            'prefillWalletId' => $prefillWalletId,
            'prefillWalletSummary' => $prefillWalletSummary,
            'allowedKinds' => $this->allowedJournalPostKinds(),
            'initialKind' => $this->resolveInitialJournalKind($request),
            'ownerCapitalWalletCode' => OwnerCapitalWallet::code(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function allowedJournalPostKinds(): array
    {
        $kinds = [];
        if (auth()->user()?->can('createExpense', JournalEntry::class)) {
            $kinds[] = 'expense';
        }
        if (auth()->user()?->can('createIncome', JournalEntry::class)) {
            $kinds[] = 'income';
        }
        if (auth()->user()?->can('createTransfer', JournalEntry::class)) {
            $kinds[] = 'transfer';
        }
        if (auth()->user()?->can('createWalletOpeningBalance', JournalEntry::class)) {
            $kinds[] = 'wallet_opening';
        }

        return $kinds;
    }

    private function resolveInitialJournalKind(Request $request): string
    {
        $allowed = $this->allowedJournalPostKinds();
        if ($allowed === []) {
            return 'expense';
        }

        $fromOld = old('entry_kind');
        if (is_string($fromOld) && in_array($fromOld, $allowed, true)) {
            return $fromOld;
        }

        $fromQuery = $request->query('kind');
        $queryMap = [
            'expense' => 'expense',
            'income' => 'income',
            'transfer' => 'transfer',
            'opening' => 'wallet_opening',
            'wallet_opening' => 'wallet_opening',
        ];
        if (is_string($fromQuery) && isset($queryMap[$fromQuery])) {
            $mapped = $queryMap[$fromQuery];
            if (in_array($mapped, $allowed, true)) {
                return $mapped;
            }
        }

        return $allowed[0];
    }

    public function store(Request $request): RedirectResponse
    {
        $allowed = $this->allowedJournalPostKinds();
        $kind = $request->validate([
            'entry_kind' => ['required', Rule::in($allowed)],
        ])['entry_kind'];

        if ($kind === 'expense') {
            $this->authorize('createExpense', JournalEntry::class);

            $data = $request->validate([
                'branch_id' => ['required', 'integer', Rule::in(auth()->user()->accessibleLocationIds())],
                'entry_date' => ['required', 'date'],
                'wallet_id' => ['required', 'integer', 'exists:wallets,id'],
                'expenses' => ['required', 'array', 'min:1'],
                'expenses.*.ledger_account_id' => ['required', 'integer', 'exists:ledger_accounts,id'],
                'expenses.*.amount' => ['required', 'numeric', 'min:0.01'],
                'expenses.*.memo' => ['nullable', 'string', 'max:500'],
            ]);

            $this->assertWalletForBranch((int) $data['wallet_id'], (int) $data['branch_id'], 'wallet_id');
            $expenseWallet = Wallet::query()->whereKey((int) $data['wallet_id'])->firstOrFail();
            OwnerCapitalWallet::assertExpenseFrom($request->user(), $expenseWallet, 'wallet_id');

            $description = collect($data['expenses'])
                ->map(fn (array $row): string => trim((string) ($row['memo'] ?? '')))
                ->filter()
                ->implode('; ');

            $entry = $this->journalService->postMultipleManualExpenses(
                (int) $data['branch_id'],
                $data['entry_date'],
                $data['expenses'],
                (int) $data['wallet_id'],
                $description !== '' ? $description : 'Operating expenses',
                (int) auth()->id(),
            );

            return redirect()->route('journal-entries.show', $entry)->with('status', 'Expenses posted.');
        }

        if ($kind === 'income') {
            $this->authorize('createIncome', JournalEntry::class);

            $data = $request->validate([
                'branch_id' => ['required', 'integer', Rule::in(auth()->user()->accessibleLocationIds())],
                'entry_date' => ['required', 'date'],
                'wallet_id' => ['required', 'integer', 'exists:wallets,id'],
                'income_lines' => ['required', 'array', 'min:1'],
                'income_lines.*.ledger_account_id' => ['required', 'integer', 'exists:ledger_accounts,id'],
                'income_lines.*.amount' => ['required', 'numeric', 'min:0.01'],
                'income_lines.*.memo' => ['nullable', 'string', 'max:500'],
            ]);

            $this->assertWalletForBranch((int) $data['wallet_id'], (int) $data['branch_id'], 'wallet_id');

            $description = collect($data['income_lines'])
                ->map(fn (array $row): string => trim((string) ($row['memo'] ?? '')))
                ->filter()
                ->implode('; ');

            $entry = $this->journalService->postMultipleManualIncomes(
                (int) $data['branch_id'],
                $data['entry_date'],
                $data['income_lines'],
                (int) $data['wallet_id'],
                $description !== '' ? $description : 'Other income',
                (int) auth()->id(),
            );

            return redirect()->route('journal-entries.show', $entry)->with('status', 'Other income posted.');
        }

        if ($kind === 'wallet_opening') {
            $this->authorize('createWalletOpeningBalance', JournalEntry::class);

            $data = $request->validate([
                'branch_id' => ['required', 'integer', Rule::in(auth()->user()->accessibleLocationIds())],
                'entry_date' => ['required', 'date'],
                'wallet_id' => ['required', 'integer', 'exists:wallets,id'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'memo' => ['nullable', 'string', 'max:500'],
            ]);

            $this->assertWalletForBranch((int) $data['wallet_id'], (int) $data['branch_id'], 'wallet_id');
            $openingWallet = Wallet::query()->whereKey((int) $data['wallet_id'])->firstOrFail();
            OwnerCapitalWallet::assertOpeningBalance($request->user(), $openingWallet, 'wallet_id');

            $entry = $this->journalService->postWalletOpeningBalance(
                (int) $data['branch_id'],
                $data['entry_date'],
                (int) $data['wallet_id'],
                (float) $data['amount'],
                (string) ($data['memo'] ?? ''),
                (int) auth()->id(),
            );

            return redirect()->route('journal-entries.show', $entry)->with('status', 'Opening balance posted.');
        }

        $this->authorize('createTransfer', JournalEntry::class);

        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in(auth()->user()->accessibleLocationIds())],
            'entry_date' => ['required', 'date'],
            'from_wallet_id' => ['required', 'integer', 'exists:wallets,id'],
            'to_wallet_id' => ['required', 'integer', 'exists:wallets,id', 'different:from_wallet_id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'memo' => ['nullable', 'string', 'max:500'],
        ]);

        $this->assertWalletForBranch((int) $data['from_wallet_id'], (int) $data['branch_id'], 'from_wallet_id');
        $this->assertWalletForBranch((int) $data['to_wallet_id'], (int) $data['branch_id'], 'to_wallet_id');

        $fromWallet = Wallet::query()->whereKey((int) $data['from_wallet_id'])->firstOrFail();
        OwnerCapitalWallet::assertTransferFrom($request->user(), $fromWallet, 'from_wallet_id');

        $entry = $this->journalService->postWalletTransfer(
            (int) $data['branch_id'],
            $data['entry_date'],
            (int) $data['from_wallet_id'],
            (int) $data['to_wallet_id'],
            (float) $data['amount'],
            (string) ($data['memo'] ?? ''),
            (int) auth()->id(),
        );

        return redirect()->route('journal-entries.show', $entry)->with('status', 'Transfer posted.');
    }

    private function assertWalletForBranch(int $walletId, int $branchId, string $fieldKey = 'wallet_id'): void
    {
        $wallet = Wallet::query()->whereKey($walletId)->with('locations')->first();
        if (! $wallet || ! $wallet->appliesToLocation($branchId)) {
            throw ValidationException::withMessages([
                $fieldKey => 'This wallet is not available for the selected branch.',
            ]);
        }
    }

    public function edit(JournalEntry $journalEntry): View|RedirectResponse
    {
        if (! $journalEntry->isEditableManualEntry()) {
            return redirect()
                ->route('journal-entries.index')
                ->with('error', 'Only manual wallet entries (expense, income, transfer, opening balance) can be edited.');
        }

        $journalEntry->load('branch');

        try {
            $formData = $this->journalService->manualEntryFormData($journalEntry);
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('journal-entries.show', $journalEntry)
                ->with('error', $e->getMessage());
        }

        $wallets = $this->walletsForBranch((int) $journalEntry->branch_id);

        return view('journal-entries.edit', [
            'entry' => $journalEntry,
            'formData' => $formData,
            'wallets' => $wallets,
            'sources' => JournalEntry::sources(),
            'ownerCapitalWalletCode' => OwnerCapitalWallet::code(),
        ]);
    }

    public function update(Request $request, JournalEntry $journalEntry): RedirectResponse
    {
        if (! $journalEntry->isEditableManualEntry()) {
            return redirect()
                ->route('journal-entries.index')
                ->with('error', 'Only manual wallet entries can be updated.');
        }

        $branchId = (int) $journalEntry->branch_id;

        if ($journalEntry->source === 'manual_transfer') {
            $data = $request->validate([
                'entry_date' => ['required', 'date'],
                'from_wallet_id' => ['required', 'integer', 'exists:wallets,id'],
                'to_wallet_id' => ['required', 'integer', 'exists:wallets,id', 'different:from_wallet_id'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'description' => ['nullable', 'string', 'max:500'],
            ]);

            $this->assertWalletForBranch((int) $data['from_wallet_id'], $branchId, 'from_wallet_id');
            $this->assertWalletForBranch((int) $data['to_wallet_id'], $branchId, 'to_wallet_id');
        } else {
            $data = $request->validate([
                'entry_date' => ['required', 'date'],
                'wallet_id' => ['required', 'integer', 'exists:wallets,id'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'description' => ['nullable', 'string', 'max:500'],
            ]);

            $this->assertWalletForBranch((int) $data['wallet_id'], $branchId, 'wallet_id');

            if ($journalEntry->source === 'manual_expense') {
                $expenseWallet = Wallet::query()->whereKey((int) $data['wallet_id'])->firstOrFail();
                OwnerCapitalWallet::assertExpenseFrom($request->user(), $expenseWallet, 'wallet_id');
            }

            if ($journalEntry->source === 'wallet_opening_balance') {
                $openingWallet = Wallet::query()->whereKey((int) $data['wallet_id'])->firstOrFail();
                OwnerCapitalWallet::assertOpeningBalance($request->user(), $openingWallet, 'wallet_id');
            }
        }

        if ($journalEntry->source === 'manual_transfer') {
            $fromWallet = Wallet::query()->whereKey((int) $data['from_wallet_id'])->firstOrFail();
            OwnerCapitalWallet::assertTransferFrom($request->user(), $fromWallet, 'from_wallet_id');
        }

        $this->journalService->updateManualEntry($journalEntry, $data, $request->user());

        return redirect()
            ->route('journal-entries.index', array_filter(['wallet_id' => $request->query('wallet_id')], fn ($v) => $v !== null && $v !== ''))
            ->with('status', 'Journal entry updated. Wallet balances reflect the revised amounts.');
    }

    /**
     * @return \Illuminate\Support\Collection<int, Wallet>
     */
    private function walletsForBranch(int $branchId)
    {
        return Wallet::query()
            ->where('is_active', true)
            ->with('locations')
            ->forUserLocations(auth()->user())
            ->orderBy('code')
            ->get()
            ->filter(fn (Wallet $wallet) => $wallet->appliesToLocation($branchId))
            ->values();
    }

    public function postDraft(Request $request, JournalEntry $journalEntry)
    {
        $this->authorize('post', $journalEntry);

        if ($journalEntry->status !== 'draft') {
            return back()->with('error', 'This entry is already posted.');
        }

        $journalEntry->update(['status' => 'posted']);

        return back()->with('status', 'Journal entry posted successfully.');
    }

    public function destroyLinkedPayment(JournalEntry $journalEntry, PaymentService $paymentService): RedirectResponse
    {
        $this->authorize('view', $journalEntry);
        \Illuminate\Support\Facades\Gate::authorize('delete-payment-allocation');

        $target = $journalEntry->linkedPaymentRemovalTarget();
        if ($target === null) {
            return back()->with('error', 'This journal entry is not linked to a removable payment.');
        }

        try {
            $paymentService->removeAllocationFromInvoice(
                $target['invoice'],
                $target['allocation'],
                (int) auth()->id(),
            );
        } catch (InvalidStateException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to remove payment.');
        }

        return redirect()
            ->route('journal-entries.index')
            ->with('status', 'Payment removed. Related journal entries were deleted.');
    }

    public function destroy(JournalEntry $journalEntry)
    {
        $this->authorize('delete', $journalEntry);

        if ($journalEntry->isEditableManualEntry()) {
            $this->journalService->deleteManualJournalEntry($journalEntry);

            return redirect()->route('journal-entries.index')->with('status', 'Journal entry deleted. Wallet balances have been updated.');
        }

        if ($journalEntry->status !== 'draft') {
            if (auth()->user()?->can('delete-posted-journal-entry') && $journalEntry->isAdminPostedJournalDeleteOnly()) {
                \Illuminate\Support\Facades\DB::transaction(function () use ($journalEntry): void {
                    JournalLine::withoutGlobalScope('posted')->where('journal_entry_id', $journalEntry->id)->delete();
                    $journalEntry->delete();
                });

                return redirect()
                    ->route('journal-entries.index')
                    ->with('status', 'Journal entry deleted. Linked invoice or payment totals were not changed.');
            }

            return redirect()
                ->route('journal-entries.index')
                ->with('error', 'Only manual wallet entries or draft entries can be deleted.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($journalEntry) {
            \App\Models\JournalLine::withoutGlobalScope('posted')->where('journal_entry_id', $journalEntry->id)->delete();
            $journalEntry->delete();
        });

        return redirect()->route('journal-entries.index')->with('status', 'Draft journal entry deleted.');
    }
}
