<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use App\Models\StockTransfer;
use App\Support\LedgerBackedInventory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BranchOverviewService
{
    /**
     * Aggregates KPIs, financials, chart series, and a small stock preview for a location.
     *
     * @return array{
     *     kpis: array<string, int>,
     *     financialBalances: array{stocks: float, invoices: float, ledgers: float, expenses: float, current_balance: float},
     *     stockPreviewItems: Collection<int, object>,
     *     chartLabels: list<string>,
     *     chartSales: list<float>,
     *     chartExpenses: list<float>
     * }
     */
    public function snapshot(Location $location): array
    {
        $id = $location->id;

        $stockSummary = DB::query()
            ->fromSub(LedgerBackedInventory::balancesSubquery(), 'inventory_balances')
            ->where('inventory_balances.location_id', $id)
            ->selectRaw('COUNT(*) as rows_count, COALESCE(SUM(inventory_balances.on_hand_qty), 0) as on_hand, COALESCE(SUM(inventory_balances.reserved_qty), 0) as reserved, COALESCE(SUM(inventory_balances.available_qty), 0) as available')
            ->first();

        $kpis = [
            'stock_rows' => (int) ($stockSummary->rows_count ?? 0),
            'on_hand' => (int) ($stockSummary->on_hand ?? 0),
            'reserved' => (int) ($stockSummary->reserved ?? 0),
            'available' => (int) ($stockSummary->available ?? 0),
            'purchase_orders' => (int) DB::table('purchase_orders')->where('destination_location_id', $id)->count(),
            'goods_receipts' => (int) DB::table('goods_receipts')->where('location_id', $id)->count(),
            'sales_invoices' => (int) DB::table('sales_invoices')->where('branch_id', $id)->count(),
            'transfers_as_source' => (int) DB::table('stock_transfers')->where('source_location_id', $id)->count(),
            'transfers_as_destination' => (int) DB::table('stock_transfers')->where('destination_location_id', $id)->count(),
        ];

        $stockValue = (float) DB::query()
            ->fromSub(LedgerBackedInventory::balancesSubquery(), 'inventory_balances')
            ->join('products', 'inventory_balances.product_id', '=', 'products.id')
            ->where('inventory_balances.location_id', $id)
            ->selectRaw('COALESCE(SUM(inventory_balances.on_hand_qty * products.default_purchase_price), 0) as total_value')
            ->value('total_value');

        $invoicesBalance = (float) DB::table('sales_invoices')
            ->where('branch_id', $id)
            ->sum('amount_due');

        $walletIds = DB::table('wallet_location')
            ->where('location_id', $id)
            ->pluck('wallet_id');

        $ledgerAccountIds = DB::table('wallets')
            ->whereIn('id', $walletIds)
            ->pluck('ledger_account_id');

        $ledgersBalance = 0.0;
        if ($ledgerAccountIds->isNotEmpty()) {
            $ledgersBalance = (float) DB::table('journal_lines')
                ->whereIn('ledger_account_id', $ledgerAccountIds)
                ->selectRaw('COALESCE(SUM(debit - credit), 0) as balance')
                ->value('balance');
        }

        $expensesBalance = (float) DB::table('journal_entries')
            ->join('journal_lines', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.branch_id', $id)
            ->where('journal_entries.source', 'manual_expense')
            ->sum('journal_lines.debit');

        $stocks = round($stockValue, 2);
        $invoices = round($invoicesBalance, 2);
        $ledgers = round($ledgersBalance, 2);
        $expenses = round($expensesBalance, 2);
        $currentBalance = round($stocks + $ledgers - $invoices, 2);

        $financialBalances = [
            'stocks' => $stocks,
            'invoices' => $invoices,
            'ledgers' => $ledgers,
            'expenses' => $expenses,
            'current_balance' => $currentBalance,
        ];

        $stockPreviewItems = DB::query()
            ->fromSub(LedgerBackedInventory::balancesSubquery(), 'inventory_balances')
            ->join('products', 'inventory_balances.product_id', '=', 'products.id')
            ->where('inventory_balances.location_id', $id)
            ->select('products.name', 'products.sku', 'inventory_balances.on_hand_qty')
            ->orderByDesc('inventory_balances.on_hand_qty')
            ->limit(8)
            ->get();

        $startDate = Carbon::now()->subDays(13)->toDateString();

        $salesData = SalesInvoice::query()
            ->where('branch_id', $id)
            ->where('invoice_date', '>=', $startDate)
            ->selectRaw('DATE(invoice_date) as date, SUM(grand_total) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $expenseData = JournalEntry::query()
            ->where('journal_entries.branch_id', $id)
            ->where('journal_entries.source', 'manual_expense')
            ->where('journal_entries.entry_date', '>=', $startDate)
            ->join('journal_lines', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->selectRaw('DATE(journal_entries.entry_date) as date, SUM(journal_lines.debit) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $chartLabels = [];
        $chartSales = [];
        $chartExpenses = [];

        for ($i = 13; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->toDateString();
            $chartLabels[] = Carbon::parse($date)->format('M j');
            $chartSales[] = (float) $salesData->get($date, 0);
            $chartExpenses[] = (float) $expenseData->get($date, 0);
        }

        return [
            'kpis' => $kpis,
            'financialBalances' => $financialBalances,
            'stockPreviewItems' => $stockPreviewItems,
            'chartLabels' => $chartLabels,
            'chartSales' => $chartSales,
            'chartExpenses' => $chartExpenses,
        ];
    }

    /**
     * @return array{
     *     journalEntries: LengthAwarePaginator,
     *     stockItems: LengthAwarePaginator,
     *     salesTotals: array{grand_total: float, invoice_count: int, period_description: string},
     *     salesInvoices: LengthAwarePaginator,
     *     expenseEntries: LengthAwarePaginator,
     *     purchaseOrders: LengthAwarePaginator,
     *     goodsReceipts: LengthAwarePaginator,
     *     stockTransfers: LengthAwarePaginator,
     *     filters: array<string, string>,
     *     locationTableTotals: array{
     *         journal: array{debit: float, credit: float},
     *         stock: array{on_hand: float, reserved: float, available: float},
     *         expenses: array{amount: float},
     *         purchase_orders: array{total_amount: float},
     *     }
     * }
     */
    public function paginatedTables(Location $location, Request $request, int $perPage): array
    {
        $id = $location->id;

        $allowedTabs = ['overview', 'transactions', 'stock', 'sales', 'expenses', 'purchasing', 'transfers', 'access', 'targets'];
        $tabIn = (string) $request->input('tab', '');
        $tab = in_array($tabIn, $allowedTabs, true) ? $tabIn : '';

        $salesRangeIn = strtolower(trim((string) $request->input('sales_range', '')));
        $salesAllowedRanges = ['all_time', 'this_month', 'months_3', 'months_6', 'months_12', 'custom'];
        $rawSalesFrom = (string) $request->input('sales_date_from', '');
        $rawSalesTo = (string) $request->input('sales_date_to', '');
        if ($salesRangeIn === '' && ($rawSalesFrom !== '' || $rawSalesTo !== '')) {
            $salesRange = 'custom';
        } elseif (in_array($salesRangeIn, $salesAllowedRanges, true)) {
            $salesRange = $salesRangeIn;
        } else {
            $salesRange = 'all_time';
        }

        $salesDateFromEffective = '';
        $salesDateToEffective = '';
        $salesPeriodDescription = 'All time';

        match ($salesRange) {
            'custom' => (function () use ($rawSalesFrom, $rawSalesTo, &$salesDateFromEffective, &$salesDateToEffective, &$salesPeriodDescription): void {
                $salesDateFromEffective = $rawSalesFrom;
                $salesDateToEffective = $rawSalesTo;
                if ($salesDateFromEffective !== '' && $salesDateToEffective !== '') {
                    $salesPeriodDescription = $salesDateFromEffective.' → '.$salesDateToEffective;
                } elseif ($salesDateFromEffective !== '') {
                    $salesPeriodDescription = 'From '.$salesDateFromEffective;
                } elseif ($salesDateToEffective !== '') {
                    $salesPeriodDescription = 'Through '.$salesDateToEffective;
                } else {
                    $salesPeriodDescription = 'Custom (no dates — all time)';
                }
            })(),
            'this_month' => (function () use (&$salesDateFromEffective, &$salesDateToEffective, &$salesPeriodDescription): void {
                $salesDateFromEffective = Carbon::now()->copy()->startOfMonth()->toDateString();
                $salesDateToEffective = Carbon::now()->toDateString();
                $salesPeriodDescription = 'This month ('.$salesDateFromEffective.' → '.$salesDateToEffective.')';
            })(),
            'months_3' => (function () use (&$salesDateFromEffective, &$salesDateToEffective, &$salesPeriodDescription): void {
                $salesDateFromEffective = Carbon::now()->copy()->subMonthsNoOverflow(3)->toDateString();
                $salesDateToEffective = Carbon::now()->toDateString();
                $salesPeriodDescription = 'Last 3 months';
            })(),
            'months_6' => (function () use (&$salesDateFromEffective, &$salesDateToEffective, &$salesPeriodDescription): void {
                $salesDateFromEffective = Carbon::now()->copy()->subMonthsNoOverflow(6)->toDateString();
                $salesDateToEffective = Carbon::now()->toDateString();
                $salesPeriodDescription = 'Last 6 months';
            })(),
            'months_12' => (function () use (&$salesDateFromEffective, &$salesDateToEffective, &$salesPeriodDescription): void {
                $salesDateFromEffective = Carbon::now()->copy()->subMonthsNoOverflow(12)->toDateString();
                $salesDateToEffective = Carbon::now()->toDateString();
                $salesPeriodDescription = 'Last 12 months';
            })(),
            default => null, // all_time — leave dates empty
        };

        $filters = [
            'tab' => $tab,
            'journal_date_from' => (string) $request->input('journal_date_from', ''),
            'journal_date_to' => (string) $request->input('journal_date_to', ''),
            'journal_source' => (string) $request->input('journal_source', ''),
            'journal_q' => trim((string) $request->input('journal_q', '')),
            'stock_q' => trim((string) $request->input('stock_q', '')),
            'sales_range' => $salesRange,
            'sales_date_from' => $salesDateFromEffective,
            'sales_date_to' => $salesDateToEffective,
            'sales_q' => trim((string) $request->input('sales_q', '')),
            'expense_date_from' => (string) $request->input('expense_date_from', ''),
            'expense_date_to' => (string) $request->input('expense_date_to', ''),
            'expense_q' => trim((string) $request->input('expense_q', '')),
            'po_date_from' => (string) $request->input('po_date_from', ''),
            'po_date_to' => (string) $request->input('po_date_to', ''),
            'po_status' => (string) $request->input('po_status', ''),
            'po_q' => trim((string) $request->input('po_q', '')),
            'grn_date_from' => (string) $request->input('grn_date_from', ''),
            'grn_date_to' => (string) $request->input('grn_date_to', ''),
            'grn_q' => trim((string) $request->input('grn_q', '')),
            'transfer_date_from' => (string) $request->input('transfer_date_from', ''),
            'transfer_date_to' => (string) $request->input('transfer_date_to', ''),
            'transfer_q' => trim((string) $request->input('transfer_q', '')),
        ];

        $journalEntries = JournalEntry::query()
            ->where('branch_id', $id)
            ->withSum('lines as lines_debit_sum', 'debit')
            ->withSum('lines as lines_credit_sum', 'credit')
            ->when($filters['journal_date_from'] !== '', fn ($q) => $q->whereDate('entry_date', '>=', $filters['journal_date_from']))
            ->when($filters['journal_date_to'] !== '', fn ($q) => $q->whereDate('entry_date', '<=', $filters['journal_date_to']))
            ->when($filters['journal_source'] !== '', fn ($q) => $q->where('source', $filters['journal_source']))
            ->when($filters['journal_q'] !== '', function ($q) use ($filters): void {
                $kw = $filters['journal_q'];
                $q->where(function ($sub) use ($kw): void {
                    $sub->where('document_no', 'like', "%{$kw}%")
                        ->orWhere('description', 'like', "%{$kw}%");
                });
            })
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'journal_page')
            ->withQueryString()
            ->fragment('location-activity');

        JournalEntry::loadLinkedCustomerReferences($journalEntries->getCollection());

        $stockItems = DB::query()
            ->fromSub(LedgerBackedInventory::balancesSubquery(), 'inventory_balances')
            ->join('products', 'inventory_balances.product_id', '=', 'products.id')
            ->where('inventory_balances.location_id', $id)
            ->when($filters['stock_q'] !== '', function ($q) use ($filters): void {
                $kw = $filters['stock_q'];
                $q->where(function ($sub) use ($kw): void {
                    $sub->where('products.name', 'like', "%{$kw}%")
                        ->orWhere('products.sku', 'like', "%{$kw}%");
                });
            })
            ->select(
                'products.name',
                'products.sku',
                'inventory_balances.on_hand_qty',
                'inventory_balances.reserved_qty',
                'inventory_balances.available_qty'
            )
            ->orderByDesc('inventory_balances.on_hand_qty')
            ->paginate($perPage, ['*'], 'stock_page')
            ->withQueryString()
            ->fragment('location-activity');

        $salesScopeFilter = static function (Builder $q) use ($id, $filters): void {
            $q->where('branch_id', $id)
                ->when($filters['sales_date_from'] !== '', fn ($sub) => $sub->whereDate('invoice_date', '>=', $filters['sales_date_from']))
                ->when($filters['sales_date_to'] !== '', fn ($sub) => $sub->whereDate('invoice_date', '<=', $filters['sales_date_to']))
                ->when($filters['sales_q'] !== '', function ($sub) use ($filters): void {
                    $kw = $filters['sales_q'];
                    $sub->where(function ($inner) use ($kw): void {
                        $inner->where('invoice_number', 'like', "%{$kw}%")
                            ->orWhereHas('customer', function ($cq) use ($kw): void {
                                $cq->where('name', 'like', "%{$kw}%")
                                    ->orWhere('phone', 'like', "%{$kw}%");
                            });
                    });
                });
        };

        $salesTotals = [
            'grand_total' => round((float) SalesInvoice::query()->tap($salesScopeFilter)->sum('grand_total'), 2),
            'invoice_count' => (int) SalesInvoice::query()->tap($salesScopeFilter)->count(),
            'period_description' => $salesPeriodDescription,
        ];

        $salesInvoices = SalesInvoice::query()
            ->with('customer')
            ->tap($salesScopeFilter)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'sales_page')
            ->withQueryString()
            ->fragment('location-activity');

        $expenseEntries = JournalEntry::query()
            ->where('branch_id', $id)
            ->where('source', 'manual_expense')
            ->withSum('lines as expense_debit_sum', 'debit')
            ->when($filters['expense_date_from'] !== '', fn ($q) => $q->whereDate('entry_date', '>=', $filters['expense_date_from']))
            ->when($filters['expense_date_to'] !== '', fn ($q) => $q->whereDate('entry_date', '<=', $filters['expense_date_to']))
            ->when($filters['expense_q'] !== '', fn ($q) => $q->where('description', 'like', '%'.$filters['expense_q'].'%'))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'expense_page')
            ->withQueryString()
            ->fragment('location-activity');

        $purchaseOrders = PurchaseOrder::query()
            ->with('supplier')
            ->where('destination_location_id', $id)
            ->when($filters['po_date_from'] !== '', fn ($q) => $q->whereDate('ordered_at', '>=', $filters['po_date_from']))
            ->when($filters['po_date_to'] !== '', fn ($q) => $q->whereDate('ordered_at', '<=', $filters['po_date_to']))
            ->when($filters['po_status'] !== '', fn ($q) => $q->where('status', $filters['po_status']))
            ->when($filters['po_q'] !== '', function ($q) use ($filters): void {
                $kw = $filters['po_q'];
                $q->where(function ($sub) use ($kw): void {
                    $sub->where('po_number', 'like', "%{$kw}%")
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$kw}%"));
                });
            })
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'po_page')
            ->withQueryString()
            ->fragment('location-activity');

        $goodsReceipts = GoodsReceipt::query()
            ->with('supplier')
            ->where('location_id', $id)
            ->when($filters['grn_date_from'] !== '', fn ($q) => $q->whereDate('received_at', '>=', $filters['grn_date_from']))
            ->when($filters['grn_date_to'] !== '', fn ($q) => $q->whereDate('received_at', '<=', $filters['grn_date_to']))
            ->when($filters['grn_q'] !== '', function ($q) use ($filters): void {
                $kw = $filters['grn_q'];
                $q->where(function ($sub) use ($kw): void {
                    $sub->where('grn_number', 'like', "%{$kw}%")
                        ->orWhere('delivery_note_ref', 'like', "%{$kw}%")
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$kw}%"));
                });
            })
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'grn_page')
            ->withQueryString()
            ->fragment('location-activity');

        $stockTransfers = StockTransfer::query()
            ->with(['sourceLocation', 'destinationLocation'])
            ->where(function ($query) use ($id): void {
                $query->where('source_location_id', $id)
                    ->orWhere('destination_location_id', $id);
            })
            ->when($filters['transfer_date_from'] !== '', fn ($q) => $q->whereDate('requested_at', '>=', $filters['transfer_date_from']))
            ->when($filters['transfer_date_to'] !== '', fn ($q) => $q->whereDate('requested_at', '<=', $filters['transfer_date_to']))
            ->when($filters['transfer_q'] !== '', fn ($q) => $q->where('transfer_number', 'like', '%'.$filters['transfer_q'].'%'))
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'transfer_page')
            ->withQueryString()
            ->fragment('location-activity');

        $journalTotalsRow = DB::table('journal_entries')
            ->join('journal_lines', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.branch_id', $id)
            ->when($filters['journal_date_from'] !== '', fn ($q) => $q->whereDate('journal_entries.entry_date', '>=', $filters['journal_date_from']))
            ->when($filters['journal_date_to'] !== '', fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $filters['journal_date_to']))
            ->when($filters['journal_source'] !== '', fn ($q) => $q->where('journal_entries.source', $filters['journal_source']))
            ->when($filters['journal_q'] !== '', function ($q) use ($filters): void {
                $kw = $filters['journal_q'];
                $q->where(function ($sub) use ($kw): void {
                    $sub->where('journal_entries.document_no', 'like', "%{$kw}%")
                        ->orWhere('journal_entries.description', 'like', "%{$kw}%");
                });
            })
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as total_debit, COALESCE(SUM(journal_lines.credit), 0) as total_credit')
            ->first();

        $stockTotalsRow = DB::query()
            ->fromSub(LedgerBackedInventory::balancesSubquery(), 'inventory_balances')
            ->join('products', 'inventory_balances.product_id', '=', 'products.id')
            ->where('inventory_balances.location_id', $id)
            ->when($filters['stock_q'] !== '', function ($q) use ($filters): void {
                $kw = $filters['stock_q'];
                $q->where(function ($sub) use ($kw): void {
                    $sub->where('products.name', 'like', "%{$kw}%")
                        ->orWhere('products.sku', 'like', "%{$kw}%");
                });
            })
            ->selectRaw(
                'COALESCE(SUM(inventory_balances.on_hand_qty), 0) as on_hand, '
                .'COALESCE(SUM(inventory_balances.reserved_qty), 0) as reserved, '
                .'COALESCE(SUM(inventory_balances.available_qty), 0) as available'
            )
            ->first();

        $expenseTotalsRow = DB::table('journal_entries')
            ->join('journal_lines', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.branch_id', $id)
            ->where('journal_entries.source', 'manual_expense')
            ->when($filters['expense_date_from'] !== '', fn ($q) => $q->whereDate('journal_entries.entry_date', '>=', $filters['expense_date_from']))
            ->when($filters['expense_date_to'] !== '', fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $filters['expense_date_to']))
            ->when($filters['expense_q'] !== '', fn ($q) => $q->where('journal_entries.description', 'like', '%'.$filters['expense_q'].'%'))
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as total_amount')
            ->first();

        $poTotalAmount = (float) PurchaseOrder::query()
            ->where('destination_location_id', $id)
            ->when($filters['po_date_from'] !== '', fn ($q) => $q->whereDate('ordered_at', '>=', $filters['po_date_from']))
            ->when($filters['po_date_to'] !== '', fn ($q) => $q->whereDate('ordered_at', '<=', $filters['po_date_to']))
            ->when($filters['po_status'] !== '', fn ($q) => $q->where('status', $filters['po_status']))
            ->when($filters['po_q'] !== '', function ($q) use ($filters): void {
                $kw = $filters['po_q'];
                $q->where(function ($sub) use ($kw): void {
                    $sub->where('po_number', 'like', "%{$kw}%")
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$kw}%"));
                });
            })
            ->sum('total_amount');

        $locationTableTotals = [
            'journal' => [
                'debit' => round((float) ($journalTotalsRow->total_debit ?? 0), 2),
                'credit' => round((float) ($journalTotalsRow->total_credit ?? 0), 2),
            ],
            'stock' => [
                'on_hand' => (float) ($stockTotalsRow->on_hand ?? 0),
                'reserved' => (float) ($stockTotalsRow->reserved ?? 0),
                'available' => (float) ($stockTotalsRow->available ?? 0),
            ],
            'expenses' => [
                'amount' => round((float) ($expenseTotalsRow->total_amount ?? 0), 2),
            ],
            'purchase_orders' => [
                'total_amount' => round($poTotalAmount, 2),
            ],
        ];

        return [
            'journalEntries' => $journalEntries,
            'stockItems' => $stockItems,
            'salesTotals' => $salesTotals,
            'salesInvoices' => $salesInvoices,
            'expenseEntries' => $expenseEntries,
            'purchaseOrders' => $purchaseOrders,
            'goodsReceipts' => $goodsReceipts,
            'stockTransfers' => $stockTransfers,
            'filters' => $filters,
            'locationTableTotals' => $locationTableTotals,
        ];
    }
}
