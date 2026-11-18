<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Location;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DailyTransactionController extends Controller
{
    use AppliesInventoryLocationContext;

    public function index(Request $request): View
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $today = now()->toDateString();

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

        $dateFromIn = $request->input('date_from');
        $dateToIn = $request->input('date_to');
        $hasDf = $dateFromIn !== null && $dateFromIn !== '';
        $hasDt = $dateToIn !== null && $dateToIn !== '';

        if (! $hasDf && ! $hasDt) {
            $dateFrom = $today;
            $dateTo = $today;
        } elseif ($hasDf && ! $hasDt) {
            $dateFrom = (string) $dateFromIn;
            $dateTo = $today;
        } elseif (! $hasDf && $hasDt) {
            $dateFrom = (string) $dateToIn;
            $dateTo = (string) $dateToIn;
        } else {
            $dateFrom = (string) $dateFromIn;
            $dateTo = (string) $dateToIn;
        }

        $filters = [
            'location_id' => (string) $request->input('location_id', ''),
            'wallet_id' => $walletIdFilter,
            'source' => (string) $request->input('source', ''),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
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
            ->whereIn('branch_id', auth()->user()->accessibleLocationIds());

        $entries = (clone $entriesBase)
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($this->resolvePerPage($request, 25))
            ->withQueryString();

        JournalEntry::loadLinkedCustomerReferences($entries->getCollection());

        $locations = Location::query()
            ->where('is_active', true)
            ->whereIn('id', auth()->user()->accessibleLocationIds())
            ->orderBy('name')
            ->get();

        $journalTotals = $this->journalIndexTotals($entriesBase, $walletLedgerId);

        return view('daily-transactions.index', [
            'entries' => $entries,
            'filters' => $filters,
            'locations' => $locations,
            'sources' => JournalEntry::sources(),
            'walletsForFilter' => $walletsForFilter,
            'journalTotals' => $journalTotals,
            'defaultActivityDate' => $today,
        ]);
    }

    /**
     * @return array{total_debit: float, total_credit: float, entry_count: int}
     */
    private function journalIndexTotals($entriesBase, ?int $walletLedgerId): array
    {
        $entryIdsQuery = $entriesBase->clone()->select('journal_entries.id');

        $agg = JournalLine::query()
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
}
