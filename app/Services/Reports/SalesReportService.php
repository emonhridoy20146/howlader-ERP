<?php

namespace App\Services\Reports;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\Employee;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesReportService
{
    use AppliesInventoryLocationContext;

    public const EXPORT_LIMIT = 10000;

    /**
     * @return array{q: string, date_from: string, date_to: string, branch_id: string}
     */
    public function itemWiseSalesFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
            'branch_id' => (string) $request->input('branch_id', ''),
        ];
    }

    public function itemWiseSalesBaseQuery(array $filters): Builder
    {
        return SalesInvoiceLine::query()
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->join('products', 'products.id', '=', 'sales_invoice_lines.product_id')
            ->where('sales_invoices.status', 'posted')
            ->select([
                'sales_invoice_lines.product_id',
                'products.sku',
                'products.name',
                DB::raw('SUM(sales_invoice_lines.qty) as total_qty'),
                DB::raw('SUM(sales_invoice_lines.line_total) as total_revenue'),
            ])
            ->when($filters['branch_id'] !== '', fn ($query) => $query->where('sales_invoices.branch_id', (int) $filters['branch_id']))
            ->when($filters['branch_id'] === '', function ($query) {
                $id = $this->contextLocationId();
                if ($id !== null) {
                    $query->where('sales_invoices.branch_id', $id);

                    return;
                }
                $user = auth()->user();
                if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
                    $ids = $user->accessibleLocationIds();
                    if ($ids !== []) {
                        $query->whereIn('sales_invoices.branch_id', $ids);
                    }
                }
            })
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('products.sku', 'like', "%{$keyword}%")
                        ->orWhere('products.name', 'like', "%{$keyword}%");
                });
            })
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('sales_invoices.invoice_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('sales_invoices.invoice_date', '<=', $filters['date_to']))
            ->groupBy('sales_invoice_lines.product_id', 'products.sku', 'products.name');
    }

    public function itemWiseSalesKpis(Builder $baseQuery): array
    {
        $kpiQuery = DB::table(DB::raw("({$baseQuery->toSql()}) as sub"))
            ->mergeBindings($baseQuery->getQuery());

        return [
            'total_items' => $kpiQuery->count(),
            'total_qty' => (float) $kpiQuery->sum('total_qty'),
            'total_revenue' => (float) $kpiQuery->sum('total_revenue'),
        ];
    }

    public function filterBranches(): Collection
    {
        $filterBranchesQuery = Location::query()->where('is_active', true)->orderBy('name');
        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
            $filterBranchesQuery->whereIn('id', $user->accessibleLocationIds());
        }

        return $filterBranchesQuery->get(['id', 'code', 'name']);
    }

    public function itemWiseSalesPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->itemWiseSalesFilters($request);
        $baseQuery = $this->itemWiseSalesBaseQuery($filters);

        $kpis = $this->itemWiseSalesKpis(clone $baseQuery);

        $rows = (clone $baseQuery)
            ->orderByDesc('total_qty')
            ->orderBy('products.name')
            ->paginate($perPage)->withQueryString();

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $kpis,
            'filterBranches' => $this->filterBranches(),
        ];
    }

    /**
     * @return array{rows: Collection<int, object>, filterSummary: string}
     */
    public function itemWiseSalesForExport(Request $request): array
    {
        $filters = $this->itemWiseSalesFilters($request);
        $baseQuery = $this->itemWiseSalesBaseQuery($filters);
        $rows = (clone $baseQuery)
            ->orderByDesc('total_qty')
            ->orderBy('products.name')
            ->limit(self::EXPORT_LIMIT)
            ->get();

        return [
            'rows' => $rows,
            'filterSummary' => $this->itemWiseSalesFilterSummary($filters),
        ];
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string}  $filters
     */
    public function itemWiseSalesFilterSummary(array $filters): string
    {
        $parts = [];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['branch_id'] !== '') {
            $b = Location::query()->whereKey((int) $filters['branch_id'])->first();
            $parts[] = 'Branch: '.($b ? $b->name : $filters['branch_id']);
        }
        if ($filters['date_from'] !== '') {
            $parts[] = 'From '.$filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $parts[] = 'To '.$filters['date_to'];
        }

        return $parts !== [] ? implode(' · ', $parts) : 'All filters';
    }

    /**
     * @return array{q: string, date_from: string, date_to: string, branch_id: string, representative_id: string}
     */
    public function representativeWiseSalesFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
            'branch_id' => (string) $request->input('branch_id', ''),
            'representative_id' => (string) $request->input('representative_id', ''),
        ];
    }

    public function representativeWiseSalesBaseQuery(array $filters): Builder
    {
        return SalesInvoice::query()
            ->leftJoin('employees as referrers', 'referrers.id', '=', 'sales_invoices.referrer_id')
            ->where('sales_invoices.status', 'posted')
            ->tap(fn (Builder $query) => $this->applyRepresentativeWiseSalesFilters($query, $filters))
            ->select([
                'sales_invoices.referrer_id as representative_id',
                'referrers.employee_code as representative_code',
                'referrers.first_name as representative_first_name',
                'referrers.last_name as representative_last_name',
                DB::raw('COUNT(sales_invoices.id) as total_invoices'),
                DB::raw('SUM(sales_invoices.grand_total) as total_revenue'),
                DB::raw('SUM(sales_invoices.amount_paid) as total_collected'),
                DB::raw('SUM(sales_invoices.amount_due) as total_due'),
            ])
            ->groupBy(
                'sales_invoices.referrer_id',
                'referrers.employee_code',
                'referrers.first_name',
                'referrers.last_name',
            );
    }

    /**
     * Posted invoices in the same scope as {@see representativeWiseSalesBaseQuery()}.
     *
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, representative_id: string}  $filters
     */
    public function representativeWiseSalesInvoiceDetailsQuery(array $filters): Builder
    {
        return SalesInvoice::query()
            ->leftJoin('employees as referrers', 'referrers.id', '=', 'sales_invoices.referrer_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales_invoices.customer_id')
            ->leftJoin('locations', 'locations.id', '=', 'sales_invoices.branch_id')
            ->where('sales_invoices.status', 'posted')
            ->tap(fn (Builder $query) => $this->applyRepresentativeWiseSalesFilters($query, $filters))
            ->select([
                'sales_invoices.id',
                'sales_invoices.invoice_number',
                'sales_invoices.invoice_date',
                'sales_invoices.referrer_id',
                'sales_invoices.sale_type',
                'sales_invoices.payment_status',
                'sales_invoices.grand_total',
                'sales_invoices.amount_paid',
                'sales_invoices.amount_due',
                'customers.customer_code',
                'customers.name as customer_name',
                'locations.name as branch_name',
            ])
            ->orderBy('sales_invoices.invoice_date')
            ->orderBy('sales_invoices.id');
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, representative_id: string}  $filters
     * @param  list<int|null>  $referrerIds
     * @return Collection<string, Collection<int, SalesInvoice>>
     */
    public function representativeWiseSalesInvoiceDetailsForReferrers(array $filters, array $referrerIds): Collection
    {
        if ($referrerIds === []) {
            return collect();
        }

        return collect($referrerIds)->mapWithKeys(function (?int $referrerId) use ($filters): array {
            $key = $referrerId === null ? 'none' : (string) $referrerId;

            return [$key => $this->representativeWiseSalesInvoiceDetailsForReferrer($filters, $referrerId)];
        });
    }

    /**
     * Invoices attributed to a referrer row: invoice referrer matches, or any payment on the invoice
     * was referred by this employee (covers invoices with no invoice-level referrer).
     *
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, representative_id: string}  $filters
     * @return Collection<int, SalesInvoice>
     */
    public function representativeWiseSalesInvoiceDetailsForReferrer(array $filters, ?int $referrerId): Collection
    {
        $query = $this->representativeWiseSalesInvoiceDetailsQuery($filters);

        if ($referrerId === null) {
            $query->whereNull('sales_invoices.referrer_id')
                ->whereDoesntHave('payments', fn (Builder $paymentQuery) => $paymentQuery
                    ->whereNotNull('payments.referrer_employee_id'));
        } else {
            $query->where(function (Builder $scope) use ($referrerId): void {
                $scope->where('sales_invoices.referrer_id', $referrerId)
                    ->orWhereHas('payments', fn (Builder $paymentQuery) => $paymentQuery
                        ->where('payments.referrer_employee_id', $referrerId));
            });
        }

        return $query->with([
            'payments' => fn ($paymentQuery) => $this->applyRepresentativeWiseSalesPaymentEagerLoad($paymentQuery, $filters),
            'payments.paymentMethodRef',
            'payments.referrerEmployee',
        ])->get();
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, representative_id: string}  $filters
     */
    private function applyRepresentativeWiseSalesPaymentEagerLoad($paymentQuery, array $filters)
    {
        return $paymentQuery
            ->where('payments.direction', 'inbound')
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('payments.payment_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('payments.payment_date', '<=', $filters['date_to']))
            ->orderBy('payments.payment_date')
            ->orderBy('payments.id');
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, representative_id: string}  $filters
     */
    private function applyRepresentativeWiseSalesFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['branch_id'] !== '', fn (Builder $q) => $q->where('sales_invoices.branch_id', (int) $filters['branch_id']))
            ->when($filters['branch_id'] === '', function (Builder $q): void {
                $id = $this->contextLocationId();
                if ($id !== null) {
                    $q->where('sales_invoices.branch_id', $id);

                    return;
                }
                $user = auth()->user();
                if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
                    $ids = $user->accessibleLocationIds();
                    if ($ids !== []) {
                        $q->whereIn('sales_invoices.branch_id', $ids);
                    }
                }
            })
            ->tap(fn (Builder $q) => $this->applyRepresentativeWiseSalesEmployeeFilter($q, $filters))
            ->tap(fn (Builder $q) => $this->applyRepresentativeWiseSalesDateFilter($q, $filters))
            ->when($filters['q'] !== '', function (Builder $q) use ($filters): void {
                $keyword = $filters['q'];
                $q->where(function (Builder $subQuery) use ($keyword): void {
                    $subQuery->where('referrers.employee_code', 'like', "%{$keyword}%")
                        ->orWhere('referrers.first_name', 'like', "%{$keyword}%")
                        ->orWhere('referrers.last_name', 'like', "%{$keyword}%")
                        ->orWhereHas('payments', function (Builder $paymentQuery) use ($keyword): void {
                            $paymentQuery->where('payments.direction', 'inbound')
                                ->whereHas('referrerEmployee', function (Builder $employeeQuery) use ($keyword): void {
                                    $employeeQuery->where('employees.employee_code', 'like', "%{$keyword}%")
                                        ->orWhere('employees.first_name', 'like', "%{$keyword}%")
                                        ->orWhere('employees.last_name', 'like', "%{$keyword}%");
                                });
                        });
                });
            });
    }

    /**
     * Match invoice referrers or payment-collection referrers.
     *
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, representative_id: string}  $filters
     */
    private function applyRepresentativeWiseSalesEmployeeFilter(Builder $query, array $filters): Builder
    {
        if ($filters['representative_id'] === '') {
            return $query;
        }

        $employeeId = (int) $filters['representative_id'];

        return $query->where(function (Builder $scope) use ($employeeId): void {
            $scope->where('sales_invoices.referrer_id', $employeeId)
                ->orWhereHas('payments', fn (Builder $paymentQuery) => $paymentQuery
                    ->where('payments.direction', 'inbound')
                    ->where('payments.referrer_employee_id', $employeeId));
        });
    }

    /**
     * Match invoice date or inbound payment date.
     *
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, representative_id: string}  $filters
     */
    private function applyRepresentativeWiseSalesDateFilter(Builder $query, array $filters): Builder
    {
        if ($filters['date_from'] === '' && $filters['date_to'] === '') {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($filters): void {
            $scope->where(function (Builder $invoiceDates) use ($filters): void {
                if ($filters['date_from'] !== '') {
                    $invoiceDates->whereDate('sales_invoices.invoice_date', '>=', $filters['date_from']);
                }
                if ($filters['date_to'] !== '') {
                    $invoiceDates->whereDate('sales_invoices.invoice_date', '<=', $filters['date_to']);
                }
            })->orWhereHas('payments', function (Builder $paymentQuery) use ($filters): void {
                $paymentQuery->where('payments.direction', 'inbound');
                if ($filters['date_from'] !== '') {
                    $paymentQuery->whereDate('payments.payment_date', '>=', $filters['date_from']);
                }
                if ($filters['date_to'] !== '') {
                    $paymentQuery->whereDate('payments.payment_date', '<=', $filters['date_to']);
                }
            });
        });
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, representative_id: string}  $filters
     */
    private function applyRepresentativeWiseSalesPaymentJoinedFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['branch_id'] !== '', fn (Builder $q) => $q->where('sales_invoices.branch_id', (int) $filters['branch_id']))
            ->when($filters['branch_id'] === '', function (Builder $q): void {
                $id = $this->contextLocationId();
                if ($id !== null) {
                    $q->where('sales_invoices.branch_id', $id);

                    return;
                }
                $user = auth()->user();
                if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
                    $ids = $user->accessibleLocationIds();
                    if ($ids !== []) {
                        $q->whereIn('sales_invoices.branch_id', $ids);
                    }
                }
            })
            ->when($filters['representative_id'] !== '', fn (Builder $q) => $q->where('payments.referrer_employee_id', (int) $filters['representative_id']))
            ->when($filters['date_from'] !== '', fn (Builder $q) => $q->whereDate('payments.payment_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn (Builder $q) => $q->whereDate('payments.payment_date', '<=', $filters['date_to']))
            ->when($filters['q'] !== '', function (Builder $q) use ($filters): void {
                $keyword = $filters['q'];
                $q->where(function (Builder $subQuery) use ($keyword): void {
                    $subQuery->where('referrers.employee_code', 'like', "%{$keyword}%")
                        ->orWhere('referrers.first_name', 'like', "%{$keyword}%")
                        ->orWhere('referrers.last_name', 'like', "%{$keyword}%");
                });
            });
    }

    public function representativeWiseSalesKpis(Builder $baseQuery): array
    {
        $kpiQuery = DB::table(DB::raw("({$baseQuery->toSql()}) as sub"))
            ->mergeBindings($baseQuery->getQuery());

        return [
            'total_representatives' => $kpiQuery->count(),
            'total_invoices' => (int) $kpiQuery->sum('total_invoices'),
            'total_revenue' => (float) $kpiQuery->sum('total_revenue'),
            'total_collected' => (float) $kpiQuery->sum('total_collected'),
            'total_due' => (float) $kpiQuery->sum('total_due'),
        ];
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, representative_id: string}  $filters
     * @return Collection<int, object>
     */
    public function representativeWiseSalesCombinedReferrerRows(array $filters): Collection
    {
        $invoiceRows = $this->representativeWiseSalesBaseQuery($filters)->get();

        $invoiceReferrerIds = SalesInvoice::query()
            ->where('sales_invoices.status', 'posted')
            ->tap(fn (Builder $query) => $this->applyRepresentativeWiseSalesFilters($query, $filters))
            ->whereNotNull('sales_invoices.referrer_id')
            ->distinct()
            ->pluck('sales_invoices.referrer_id');

        $paymentOnlyRows = $this->representativeWiseSalesPaymentOnlyReferrerRows($filters, $invoiceReferrerIds);

        return $invoiceRows
            ->concat($paymentOnlyRows)
            ->sort(function (object $left, object $right): int {
                $revenueCompare = (float) $right->total_revenue <=> (float) $left->total_revenue;
                if ($revenueCompare !== 0) {
                    return $revenueCompare;
                }

                $codeCompare = strcmp((string) ($left->representative_code ?? ''), (string) ($right->representative_code ?? ''));
                if ($codeCompare !== 0) {
                    return $codeCompare;
                }

                return strcmp((string) ($left->representative_first_name ?? ''), (string) ($right->representative_first_name ?? ''));
            })
            ->values();
    }

    /**
     * Referrers who appear only on payments (invoice has no referrer, or a different invoice referrer).
     *
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, representative_id: string}  $filters
     * @param  Collection<int, int|string|null>  $invoiceReferrerIds
     * @return Collection<int, object>
     */
    private function representativeWiseSalesPaymentOnlyReferrerRows(array $filters, Collection $invoiceReferrerIds): Collection
    {
        $distinctInvoices = SalesInvoice::query()
            ->join('payment_allocations', 'payment_allocations.sales_invoice_id', '=', 'sales_invoices.id')
            ->join('payments', function ($join): void {
                $join->on('payments.id', '=', 'payment_allocations.payment_id')
                    ->where('payments.direction', 'inbound');
            })
            ->join('employees as referrers', 'referrers.id', '=', 'payments.referrer_employee_id')
            ->where('sales_invoices.status', 'posted')
            ->whereNotNull('payments.referrer_employee_id')
            ->where(function (Builder $scope): void {
                $scope->whereNull('sales_invoices.referrer_id')
                    ->orWhereColumn('sales_invoices.referrer_id', '!=', 'payments.referrer_employee_id');
            })
            ->when($invoiceReferrerIds->isNotEmpty(), fn (Builder $query) => $query->whereNotIn('payments.referrer_employee_id', $invoiceReferrerIds))
            ->tap(fn (Builder $query) => $this->applyRepresentativeWiseSalesPaymentJoinedFilters($query, $filters))
            ->select([
                'payments.referrer_employee_id as representative_id',
                'referrers.employee_code as representative_code',
                'referrers.first_name as representative_first_name',
                'referrers.last_name as representative_last_name',
                'sales_invoices.id as invoice_id',
                'sales_invoices.grand_total',
                'sales_invoices.amount_paid',
                'sales_invoices.amount_due',
            ])
            ->distinct();

        return DB::query()
            ->fromSub($distinctInvoices, 'payment_referrer_invoices')
            ->select([
                'representative_id',
                'representative_code',
                'representative_first_name',
                'representative_last_name',
                DB::raw('COUNT(*) as total_invoices'),
                DB::raw('SUM(grand_total) as total_revenue'),
                DB::raw('SUM(amount_paid) as total_collected'),
                DB::raw('SUM(amount_due) as total_due'),
            ])
            ->groupBy(
                'representative_id',
                'representative_code',
                'representative_first_name',
                'representative_last_name',
            )
            ->get()
            ->map(function (object $row): object {
                $row->is_payment_referrer_only = true;

                return $row;
            });
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, representative_id: string}  $filters
     */
    private function representativeWiseSalesCombinedReferrerKpis(array $filters): array
    {
        $rows = $this->representativeWiseSalesCombinedReferrerRows($filters);

        return [
            'total_representatives' => $rows->count(),
            'total_invoices' => (int) $rows->sum('total_invoices'),
            'total_revenue' => (float) $rows->sum('total_revenue'),
            'total_collected' => (float) $rows->sum('total_collected'),
            'total_due' => (float) $rows->sum('total_due'),
        ];
    }

    public function filterRepresentatives(): Collection
    {
        return Employee::query()
            ->where('is_active', true)
            ->orderBy('employee_code')
            ->orderBy('first_name')
            ->get(['id', 'employee_code', 'first_name', 'last_name']);
    }

    public function representativeWiseSalesPaginated(Request $request, int $perPage = 50): array
    {
        $filters = $this->representativeWiseSalesFilters($request);
        $combinedRows = $this->representativeWiseSalesCombinedReferrerRows($filters);
        $kpis = $this->representativeWiseSalesCombinedReferrerKpis($filters);

        $page = max(1, (int) $request->input('page', 1));
        $rows = new LengthAwarePaginator(
            $combinedRows->forPage($page, $perPage)->values(),
            $combinedRows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $referrerIds = collect($rows->items())->pluck('representative_id')->unique()->values()->all();
        $invoiceDetailsByReferrer = $this->representativeWiseSalesInvoiceDetailsForReferrers($filters, $referrerIds);

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $kpis,
            'filterBranches' => $this->filterBranches(),
            'filterRepresentatives' => $this->filterRepresentatives(),
            'invoiceDetailsByReferrer' => $invoiceDetailsByReferrer,
        ];
    }

    /**
     * @return array{rows: Collection<int, object>, filterSummary: string}
     */
    public function representativeWiseSalesForExport(Request $request): array
    {
        $filters = $this->representativeWiseSalesFilters($request);
        $rows = $this->representativeWiseSalesCombinedReferrerRows($filters)->take(self::EXPORT_LIMIT);

        $referrerIds = $rows->pluck('representative_id')->unique()->values()->all();
        $invoiceDetailsByReferrer = $this->representativeWiseSalesInvoiceDetailsForReferrers($filters, $referrerIds);

        return [
            'rows' => $rows,
            'invoiceDetailsByReferrer' => $invoiceDetailsByReferrer,
            'filterSummary' => $this->representativeWiseSalesFilterSummary($filters),
        ];
    }

    /**
     * @param  array{q: string, date_from: string, date_to: string, branch_id: string, representative_id: string}  $filters
     */
    public function representativeWiseSalesFilterSummary(array $filters): string
    {
        $parts = [];
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['branch_id'] !== '') {
            $b = Location::query()->whereKey((int) $filters['branch_id'])->first();
            $parts[] = 'Branch: '.($b ? $b->name : $filters['branch_id']);
        }
        if ($filters['representative_id'] !== '') {
            $rep = Employee::query()->whereKey((int) $filters['representative_id'])->first();
            $parts[] = 'Referrer: '.($rep ? $rep->displayLabel() : $filters['representative_id']);
        }
        if ($filters['date_from'] !== '') {
            $parts[] = 'From '.$filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $parts[] = 'To '.$filters['date_to'];
        }

        return $parts !== [] ? implode(' · ', $parts) : 'All filters';
    }
}
