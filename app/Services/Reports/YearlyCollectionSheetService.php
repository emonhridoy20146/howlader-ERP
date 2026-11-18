<?php

namespace App\Services\Reports;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\Location;
use App\Models\User;
use App\Services\InstallmentAdvanceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Yearly Collection Sheet: unpaid / not fully paid installment invoices grouped by customer,
 * with a paid/missed grid for every month of a year range.
 *
 * Definitions
 *  - Included invoice: not soft-deleted, not draft/cancelled, has an installment plan that is not
 *    cancelled, and still has an unpaid schedule (due + late fee - paid_amount > 0; write-offs are
 *    already part of schedules.paid_amount). "Include fully paid" lifts the unpaid condition.
 *  - Paid in month M: inbound payment allocations on the invoice dated in M, i.e. the same source as
 *    the Monthly Collection report ("paid in selected period"). Down payments (the advance
 *    reference) are included unless "Include down payment" is off.
 *  - Due in month M: schedules whose due_date falls in M (amount = due + late fee).
 *  - Unpaid(M): for schedules due in M with due_date <= today: due + late fee - settled-before-payments
 *    (paid_amount not backed by an allocation, e.g. write-offs) - installment allocations dated
 *    <= min(end of M, today).
 *  - Missed: Unpaid(M) > 0 and nothing paid on the invoice in M. Partial: Unpaid(M) > 0 and something
 *    paid in M. Upcoming: schedules due in M after today and unpaid. Paid: paid in M, nothing unpaid.
 *  - Total Payable: the invoice grand total (customer and grand totals add each invoice once). Total Paid: the invoice's recorded paid amount (amount_paid, lifetime; the month cells still show payments per month). Total Due:
 *    unpaid today on schedules due in range with due_date <= today. Outstanding: remaining on the whole plan.
 */
class YearlyCollectionSheetService
{
    use AppliesInventoryLocationContext;

    public const EPS = 0.009;

    public const PER_PAGE = 25;

    /** @return array<string, mixed> */
    public function filters(Request $request): array
    {
        $max = (int) config('reports.yearly_collection_max_years', 3);
        $currentYear = (int) now()->year;

        $input = [
            'year_from' => $request->filled('year_from') ? $request->input('year_from') : $currentYear - 1,
            'year_to' => $request->filled('year_to') ? $request->input('year_to') : $currentYear,
        ];

        $validator = Validator::make($input, [
            'year_from' => ['required', 'integer', 'min:2000', 'max:'.($currentYear + 1)],
            'year_to' => ['required', 'integer', 'min:'.(int) $input['year_from'], 'max:'.($currentYear + 1)],
        ], [
            'year_to.min' => 'The end year cannot be before the start year.',
        ]);
        $validator->after(function ($v) use ($input, $max): void {
            if (! $v->errors()->any() && ((int) $input['year_to'] - (int) $input['year_from'] + 1) > $max) {
                $v->errors()->add('year_to', "The year range can span at most {$max} years.");
            }
        });
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $status = (string) $request->input('status', 'all');
        $sort = (string) $request->input('sort', 'name');

        return [
            'year_from' => (int) $input['year_from'],
            'year_to' => (int) $input['year_to'],
            'branch_ids' => collect(array_merge((array) $request->input('branch_ids', []), $request->filled('branch_id') ? [$request->input('branch_id')] : []))
                ->filter(fn ($id) => ctype_digit((string) $id))->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'customer_id' => ctype_digit((string) $request->input('customer_id', '')) ? (string) $request->input('customer_id') : '',
            'q' => trim((string) $request->input('q', '')),
            'status' => in_array($status, ['all', 'has_missed', 'overdue'], true) ? $status : 'all',
            'plan_status' => in_array($request->input('plan_status'), ['active', 'completed', 'defaulted'], true) ? (string) $request->input('plan_status') : '',
            'collector_id' => $this->normalizeCollector($request->input('collector_id')),
            'sort' => in_array($sort, ['name', 'code', 'due'], true) ? $sort : 'name',
            'include_paid' => $request->boolean('include_paid', false),
            'include_down_payment' => $request->boolean('include_down_payment', true),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function filterSummary(array $filters): string
    {
        $parts = ['Yearly Collection Sheet', 'Years '.$filters['year_from'].'–'.$filters['year_to']];
        if ($filters['branch_ids'] !== []) {
            $names = Location::query()->whereIn('id', $filters['branch_ids'])->orderBy('name')->pluck('name')->all();
            $parts[] = (count($names) === 1 ? 'Branch: ' : 'Branches: ').implode(', ', $names);
        }
        if ($filters['customer_id'] !== '') {
            $parts[] = 'Customer #'.$filters['customer_id'];
        }
        if ($filters['q'] !== '') {
            $parts[] = 'Search: '.$filters['q'];
        }
        if ($filters['status'] !== 'all') {
            $parts[] = $filters['status'] === 'has_missed' ? 'Has missed months' : 'Overdue only';
        }
        if ($filters['plan_status'] !== '') {
            $parts[] = 'Plan: '.$filters['plan_status'];
        }
        if ($filters['collector_id'] !== '') {
            $parts[] = $filters['collector_id'] === 'unknown'
                ? 'Collected by: Unknown'
                : 'Collected by: '.(User::query()->whereKey((int) $filters['collector_id'])->first()?->displayName() ?? $filters['collector_id']);
        }
        if ($filters['include_paid']) {
            $parts[] = 'Including fully paid';
        }
        if (! $filters['include_down_payment']) {
            $parts[] = 'Excluding down payments';
        }

        return implode(' · ', $parts);
    }

    /** @return Collection<int, array{id: int, name: string}> */
    public function collectorOptions(): Collection
    {
        return User::query()
            ->whereIn('id', DB::table('payments')->whereNotNull('recorded_by')->select('recorded_by'))
            ->get(['id', 'name', 'full_name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->displayName()])
            ->sortBy(fn (array $u) => mb_strtolower($u['name']))
            ->values();
    }

    /** @return Collection<int, Location> */
    public function branchOptions(): Collection
    {
        $q = Location::query()->where('is_active', true)->orderBy('name');
        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
            $q->whereIn('id', $user->accessibleLocationIds());
        }

        return $q->get(['id', 'code', 'name']);
    }

    /**
     * One page of customers plus whole-result summary cards.
     *
     * @param  array<string, mixed>  $filters
     * @return array{filters: array, months: list<array>, years: array<int, int>, customers: list<array>, grand: array, paginator: LengthAwarePaginator, kpis: array}
     */
    public function paginated(array $filters, int $perPage = self::PER_PAGE): array
    {
        $paginator = $this->customerQuery($filters)->paginate($perPage)->withQueryString();
        $customers = $this->build($this->pairs($paginator->getCollection()), $filters);

        return [
            'filters' => $filters,
            'months' => $this->months($filters),
            'years' => $this->years($filters),
            'customers' => $customers,
            'groups' => $this->groupByBranch($customers, $filters),
            'grand' => $this->grandTotals($customers, $filters),
            'paginator' => $paginator,
            'kpis' => $this->kpis($filters),
            'credit' => $this->credit($filters),
        ];
    }

    /**
     * Every matching customer, built in chunks (print, PDF, Excel).
     *
     * @param  array<string, mixed>  $filters
     * @return array{filters: array, months: list<array>, years: array<int, int>, customers: list<array>, grand: array}
     */
    public function forExport(array $filters, int $limit = 5000): array
    {
        $pairs = $this->pairs($this->customerQuery($filters)->limit($limit)->get());
        $customers = [];
        foreach (array_chunk($pairs, self::PER_PAGE) as $chunk) {
            foreach ($this->build($chunk, $filters) as $customer) {
                $customers[] = $customer;
            }
        }

        return [
            'filters' => $filters,
            'months' => $this->months($filters),
            'years' => $this->years($filters),
            'customers' => $customers,
            'groups' => $this->groupByBranch($customers, $filters),
            'grand' => $this->grandTotals($customers, $filters),
            'credit' => $this->credit($filters, $limit),
        ];
    }

    /**
     * @param  iterable<int, object>  $rows
     * @return list<array{id: int, branch_id: int}>
     */
    private function pairs(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r->id, 'branch_id' => (int) $r->branch_id];
        }

        return $out;
    }

    /**
     * Customer blocks grouped by invoice branch, in page order, each group with its own totals.
     *
     * @param  list<array<string, mixed>>  $customers
     * @return list<array<string, mixed>>
     */
    public function groupByBranch(array $customers, array $filters): array
    {
        $keys = array_column($this->months($filters), 'key');
        $groups = [];
        foreach ($customers as $customer) {
            $groups[$customer['branch_id']]['id'] = $customer['branch_id'];
            $groups[$customer['branch_id']]['name'] = $customer['branch_name'];
            $groups[$customer['branch_id']]['customers'][] = $customer;
        }

        return array_values(array_map(
            fn (array $g) => $g + $this->sumRows($g['customers'], $keys) + ['invoices' => array_sum(array_map(fn ($c) => count($c['invoices']), $g['customers']))],
            $groups,
        ));
    }

    /** @return list<array{key: string, year: int, month: int, label: string}> */
    public function months(array $filters): array
    {
        $out = [];
        for ($y = $filters['year_from']; $y <= $filters['year_to']; $y++) {
            for ($m = 1; $m <= 12; $m++) {
                $out[] = ['key' => sprintf('%04d-%02d', $y, $m), 'year' => $y, 'month' => $m, 'label' => Carbon::create($y, $m, 1)->format('M')];
            }
        }

        return $out;
    }

    /**
     * Drop whole years with no activity (nothing paid, due, unpaid, upcoming or sold) in the given rows.
     * Keeps every year when nothing is active at all.
     *
     * @param  list<array{key: string, year: int, month: int, label: string}>  $months
     * @param  iterable<int, array<string, array<string, mixed>>>  $monthMaps  each row's months map
     * @return array{months: list<array>, years: array<int, int>}
     */
    public static function visibleYears(array $months, iterable $monthMaps): array
    {
        $active = [];
        foreach ($monthMaps as $map) {
            foreach ($map as $key => $cell) {
                if (($cell['state'] ?? null) !== null || ($cell['paid'] ?? 0) > 0 || ($cell['due'] ?? 0) > 0
                    || ($cell['arrears'] ?? 0) > 0 || ($cell['sold'] ?? 0) > 0) {
                    $active[(int) substr($key, 0, 4)] = true;
                }
            }
        }
        $kept = $active === [] ? $months : array_values(array_filter($months, fn ($m) => isset($active[$m['year']])));
        $years = [];
        foreach ($kept as $m) {
            $years[$m['year']] = ($years[$m['year']] ?? 0) + 1;
        }

        return ['months' => $kept, 'years' => $years];
    }

    /** @param list<array<string, mixed>> $customers  @return list<array<string, array<string, mixed>>> */
    public static function invoiceMonthMaps(array $customers): array
    {
        $maps = [];
        foreach ($customers as $c) {
            foreach ($c['invoices'] as $row) {
                $maps[] = $row['months'];
            }
        }

        return $maps;
    }

    /** @return array<int, int> year => month columns */
    public function years(array $filters): array
    {
        $out = [];
        for ($y = $filters['year_from']; $y <= $filters['year_to']; $y++) {
            $out[$y] = 12;
        }

        return $out;
    }

    // ---------------------------------------------------------------- queries

    /** @return array{0: string, 1: string} */
    private function rangeBounds(array $filters): array
    {
        return [
            Carbon::create($filters['year_from'], 1, 1)->toDateString(),
            Carbon::create($filters['year_to'], 12, 31)->toDateString(),
        ];
    }

    private function normalizeCollector(mixed $value): string
    {
        $value = trim((string) $value);

        return ($value === 'unknown' || ctype_digit($value)) ? $value : '';
    }

    private function branchAllowed(int $branchId): bool
    {
        if ($branchId <= 0 || ! Location::query()->whereKey($branchId)->where('is_active', true)->exists()) {
            return false;
        }
        $user = auth()->user();
        if (! $user || $user->isAdmin() || $user->hasPermission('location.view-all')) {
            return true;
        }

        return in_array($branchId, $user->accessibleLocationIds(), true);
    }

    /** Qualifying invoices joined to their plan (alias si / p). */
    private function invoiceBase(array $filters): QueryBuilder
    {
        [$start, $end] = $this->rangeBounds($filters);
        $today = Carbon::today()->toDateString();
        $remaining = '(s.due_amount + COALESCE(s.late_fee_amount, 0) - s.paid_amount) > '.self::EPS;

        $q = DB::table('sales_invoices as si')
            ->join('installment_plans as p', 'p.sales_invoice_id', '=', 'si.id')
            ->whereNull('si.deleted_at')
            ->whereNotIn('si.status', ['draft', 'cancelled', 'returned'])
            ->where('p.status', '!=', 'cancelled');

        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
            $q->whereIn('si.branch_id', $user->accessibleLocationIds() ?: [0]);
        }
        if ($filters['branch_ids'] !== []) {
            $allowed = array_values(array_filter($filters['branch_ids'], fn (int $id) => $this->branchAllowed($id)));
            $allowed === [] ? $q->whereRaw('1 = 0') : $q->whereIn('si.branch_id', $allowed);
        } elseif ($this->contextLocationId() !== null) {
            $q->where('si.branch_id', $this->contextLocationId());
        }
        if ($filters['customer_id'] !== '') {
            $q->where('si.customer_id', (int) $filters['customer_id']);
        }
        if ($filters['plan_status'] !== '') {
            $q->where('p.status', $filters['plan_status']);
        }
        if (! $filters['include_paid']) {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('installment_schedules as s')->whereColumn('s.installment_plan_id', 'p.id')->whereRaw($remaining));
        }
        if ($filters['status'] === 'has_missed') {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('installment_schedules as s')->whereColumn('s.installment_plan_id', 'p.id')
                ->whereRaw($remaining)->whereDate('s.due_date', '>=', $start)->whereDate('s.due_date', '<=', min($end, $today)));
        } elseif ($filters['status'] === 'overdue') {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('installment_schedules as s')->whereColumn('s.installment_plan_id', 'p.id')
                ->whereRaw($remaining)->whereDate('s.due_date', '<', $today));
        }
        if ($filters['collector_id'] !== '') {
            $q->whereExists(function ($s) use ($filters, $start, $end): void {
                $s->selectRaw('1')->from('payment_allocations as pa')->join('payments as pay', 'pay.id', '=', 'pa.payment_id')
                    ->whereColumn('pa.sales_invoice_id', 'si.id')->where('pay.direction', 'inbound')
                    ->whereDate('pay.payment_date', '>=', $start)->whereDate('pay.payment_date', '<=', $end);
                $filters['collector_id'] === 'unknown' ? $s->whereNull('pay.recorded_by') : $s->where('pay.recorded_by', (int) $filters['collector_id']);
            });
        }

        return $q;
    }

    /** One row per (invoice branch, customer), ordered branch first. */
    private function customerQuery(array $filters): QueryBuilder
    {
        [$start, $end] = $this->rangeBounds($filters);
        $today = Carbon::today()->toDateString();

        $pairs = $this->invoiceBase($filters)->select('si.customer_id', 'si.branch_id')->distinct();
        $q = DB::table('customers as c')
            ->joinSub($pairs, 'bp', 'bp.customer_id', '=', 'c.id')
            ->join('locations as lb', 'lb.id', '=', 'bp.branch_id')
            ->select(['c.id', 'c.name', 'c.customer_code', 'bp.branch_id', 'lb.name as branch_name']);

        if ($filters['q'] !== '') {
            $kw = '%'.$filters['q'].'%';
            $q->where(fn ($w) => $w->where('c.name', 'like', $kw)->orWhere('c.customer_code', 'like', $kw)->orWhere('c.phone', 'like', $kw));
        }

        $q->orderBy('lb.name')->orderBy('lb.id');

        if ($filters['sort'] === 'due') {
            $due = $this->invoiceBase($filters)
                ->join('installment_schedules as s', 's.installment_plan_id', '=', 'p.id')
                ->whereDate('s.due_date', '>=', $start)->whereDate('s.due_date', '<=', min($end, $today))
                ->selectRaw('si.customer_id as customer_id, si.branch_id as branch_id, SUM(CASE WHEN (s.due_amount + COALESCE(s.late_fee_amount, 0) - s.paid_amount) > 0 THEN (s.due_amount + COALESCE(s.late_fee_amount, 0) - s.paid_amount) ELSE 0 END) as total_due')
                ->groupBy('si.customer_id', 'si.branch_id');
            $q->leftJoinSub($due, 'd', fn ($join) => $join->on('d.customer_id', '=', 'c.id')->on('d.branch_id', '=', 'bp.branch_id'))
                ->orderByRaw('COALESCE(d.total_due, 0) DESC');
        }

        return $q->orderBy($filters['sort'] === 'code' ? 'c.customer_code' : 'c.name')->orderBy('c.id');
    }

    /**
     * Summary cards over the whole result (SQL, as of today).
     *
     * @return array{customers: int, invoices: int, total_paid: float, total_due: float, missed_months: int}
     */
    private function kpis(array $filters): array
    {
        [$start, $end] = $this->rangeBounds($filters);
        $today = Carbon::today()->toDateString();
        $base = fn () => $this->invoiceBase($filters)->when($filters['q'] !== '', function ($q) use ($filters): void {
            $kw = '%'.$filters['q'].'%';
            $q->whereIn('si.customer_id', DB::table('customers')->where(fn ($w) => $w->where('name', 'like', $kw)->orWhere('customer_code', 'like', $kw)->orWhere('phone', 'like', $kw))->select('id'));
        });

        $invoices = (int) $base()->distinct()->count('si.id');
        $customerCount = (int) $base()->distinct()->count('si.customer_id');

        $paid = DB::table('payment_allocations as pa')
            ->join('payments as pay', 'pay.id', '=', 'pa.payment_id')
            ->whereIn('pa.sales_invoice_id', $base()->select('si.id'))
            ->where('pay.direction', 'inbound')
            ->whereDate('pay.payment_date', '>=', $start)->whereDate('pay.payment_date', '<=', $end)
            ->when(! $filters['include_down_payment'], fn ($q) => $q->where(fn ($w) => $w->whereNull('pay.reference_no')->orWhere('pay.reference_no', '!=', InstallmentAdvanceService::REFERENCE)))
            ->when($filters['collector_id'] === 'unknown', fn ($q) => $q->whereNull('pay.recorded_by'))
            ->when(ctype_digit($filters['collector_id']), fn ($q) => $q->where('pay.recorded_by', (int) $filters['collector_id']))
            ->sum('pa.allocated_amount');

        $unpaid = $base()
            ->join('installment_schedules as s', 's.installment_plan_id', '=', 'p.id')
            ->whereDate('s.due_date', '>=', $start)->whereDate('s.due_date', '<=', min($end, $today))
            ->whereRaw('(s.due_amount + COALESCE(s.late_fee_amount, 0) - s.paid_amount) > '.self::EPS)
            ->get(['si.id as invoice_id', 's.due_date', DB::raw('(s.due_amount + COALESCE(s.late_fee_amount, 0) - s.paid_amount) as remaining')]);

        return [
            'customers' => $customerCount,
            'invoices' => $invoices,
            'total_paid' => round((float) $paid, 2),
            'total_due' => round((float) $unpaid->sum('remaining'), 2),
            'missed_months' => $unpaid->map(fn ($r) => $r->invoice_id.'|'.substr((string) $r->due_date, 0, 7))->unique()->count(),
        ];
    }

    // ------------------------------------------------------------------ build

    /**
     * @param  list<array{id: int, branch_id: int}>  $pairs  customer + invoice branch; order is preserved
     * @return list<array<string, mixed>>
     */
    public function build(array $pairs, array $filters): array
    {
        if ($pairs === []) {
            return [];
        }
        $customerIds = array_values(array_unique(array_column($pairs, 'id')));

        [$start, $end] = $this->rangeBounds($filters);
        $today = Carbon::today();
        $monthKeys = array_column($this->months($filters), 'key');

        $customers = DB::table('customers as c')->leftJoin('locations as cb', 'cb.id', '=', 'c.branch_id')
            ->whereIn('c.id', $customerIds)->get(['c.id', 'c.customer_code', 'c.name', 'c.phone', 'cb.name as branch_name'])->keyBy('id');

        $invoices = $this->invoiceBase($filters)->whereIn('si.customer_id', $customerIds)
            ->leftJoin('locations as ib', 'ib.id', '=', 'si.branch_id')
            ->orderBy('si.invoice_date')->orderBy('si.id')
            ->get(['si.id', 'si.customer_id', 'si.invoice_number', 'si.invoice_date', 'si.branch_id', 'si.grand_total', 'si.amount_paid', 'ib.name as branch_name', 'p.id as plan_id', 'p.status as plan_status']);

        $planIds = $invoices->pluck('plan_id')->all();
        $invoiceIds = $invoices->pluck('id')->all();

        $schedules = $planIds === [] ? collect() : DB::table('installment_schedules')->whereIn('installment_plan_id', $planIds)
            ->orderBy('installment_no')->get(['id', 'installment_plan_id', 'installment_no', 'due_date', 'due_amount', 'late_fee_amount', 'paid_amount'])
            ->groupBy('installment_plan_id');

        $collector = $filters['collector_id'];
        $applyCollector = fn ($q) => $collector === 'unknown' ? $q->whereNull('pay.recorded_by') : ($collector !== '' ? $q->where('pay.recorded_by', (int) $collector) : $q);

        $scheduleIds = $schedules->flatten(1)->pluck('id')->all();
        $installmentAllocs = $scheduleIds === [] ? collect() : DB::table('installment_payment_allocations as ia')
            ->join('payments as pay', 'pay.id', '=', 'ia.payment_id')
            ->whereIn('ia.installment_schedule_id', $scheduleIds)->where('pay.direction', 'inbound')
            ->get(['ia.installment_schedule_id as schedule_id', 'ia.allocated_amount', 'pay.payment_date'])
            ->groupBy('schedule_id');

        $invoiceAllocs = $invoiceIds === [] ? collect() : DB::table('payment_allocations as pa')
            ->join('payments as pay', 'pay.id', '=', 'pa.payment_id')
            ->whereIn('pa.sales_invoice_id', $invoiceIds)->where('pay.direction', 'inbound')
            ->whereDate('pay.payment_date', '>=', $start)->whereDate('pay.payment_date', '<=', $end)
            ->when(! $filters['include_down_payment'], fn ($q) => $q->where(fn ($w) => $w->whereNull('pay.reference_no')->orWhere('pay.reference_no', '!=', InstallmentAdvanceService::REFERENCE)))
            ->tap($applyCollector)
            ->get(['pa.sales_invoice_id as invoice_id', 'pa.allocated_amount', 'pay.payment_date'])
            ->groupBy('invoice_id');

        $byBlock = $invoices->groupBy(fn ($inv) => $inv->customer_id.'|'.$inv->branch_id);
        $out = [];
        foreach ($pairs as $pair) {
            $customerId = $pair['id'];
            $c = $customers[$customerId] ?? null;
            $blockInvoices = $byBlock[$customerId.'|'.$pair['branch_id']] ?? null;
            if (! $c || ! $blockInvoices) {
                continue;
            }
            $rows = [];
            foreach ($blockInvoices as $inv) {
                $rows[] = $this->invoiceRow($inv, $schedules[$inv->plan_id] ?? collect(), $installmentAllocs, $invoiceAllocs[$inv->id] ?? collect(), $monthKeys, $today, $start, $end);
            }
            $out[] = [
                'id' => (int) $c->id, 'code' => $c->customer_code, 'name' => $c->name, 'phone' => $c->phone, 'branch' => $c->branch_name,
                'branch_id' => (int) $pair['branch_id'], 'branch_name' => $blockInvoices->first()->branch_name,
                'invoices' => $rows,
            ] + $this->sumRows($rows, $monthKeys);
        }

        return $out;
    }

    /**
     * @param  Collection<int, object>  $schedules
     * @param  Collection<int, Collection<int, object>>  $installmentAllocs  keyed by schedule id
     * @param  Collection<int, object>  $paidRows  invoice-level allocations in range
     * @param  list<string>  $monthKeys
     * @return array<string, mixed>
     */
    private function invoiceRow(object $inv, Collection $schedules, Collection $installmentAllocs, Collection $paidRows, array $monthKeys, Carbon $today, string $start, string $end): array
    {
        $paidByMonth = [];
        foreach ($paidRows as $r) {
            $key = substr((string) $r->payment_date, 0, 7);
            $paidByMonth[$key] = ($paidByMonth[$key] ?? 0) + (float) $r->allocated_amount;
        }

        $unpaidByMonth = $upcomingByMonth = [];
        $totalDue = 0.0;
        $dueInst = 0;
        $outstanding = 0.0;
        $paidInst = 0;
        $partialInst = 0;
        $lastDue = null;
        $expires = null;
        $todayStr = $today->toDateString();

        foreach ($schedules as $s) {
            $due = round((float) $s->due_amount + (float) $s->late_fee_amount, 2);
            $allocs = $installmentAllocs[$s->id] ?? collect();
            $allocSum = (float) $allocs->sum('allocated_amount');
            $other = max(0.0, (float) $s->paid_amount - $allocSum); // write-offs / legacy paid amounts without an allocation
            $outstanding += max(0.0, $due - (float) $s->paid_amount);
            if ($due - (float) $s->paid_amount <= self::EPS) {
                $paidInst++;
            } elseif ((float) $s->paid_amount > self::EPS) {
                $partialInst++; // some money received, not fully settled
            }
            $dueDate = substr((string) $s->due_date, 0, 10);
            $expires = $expires === null || $dueDate > $expires ? $dueDate : $expires;
            if ($dueDate <= $todayStr) {
                $lastDue = $lastDue === null || $dueDate > $lastDue ? $dueDate : $lastDue;
            }
            if ($dueDate < $start || $dueDate > $end) {
                continue;
            }
            $key = substr($dueDate, 0, 7);

            if ($dueDate > $todayStr) {
                $upcomingByMonth[$key] = ($upcomingByMonth[$key] ?? 0) + max(0.0, $due - (float) $s->paid_amount);

                continue;
            }

            $cutoff = min(Carbon::parse($dueDate)->endOfMonth()->toDateString(), $todayStr);
            $paidBy = (float) $allocs->filter(fn ($a) => substr((string) $a->payment_date, 0, 10) <= $cutoff)->sum('allocated_amount');
            $unpaid = max(0.0, $due - $other - $paidBy);
            $unpaidByMonth[$key] = ($unpaidByMonth[$key] ?? 0) + $unpaid;
            $today_unpaid = max(0.0, $due - $other - $allocSum);
            if ($today_unpaid > self::EPS) {
                $totalDue += $today_unpaid;
                $dueInst++;
            }
        }

        $months = [];
        $missedMonths = 0;
        foreach ($monthKeys as $key) {
            $p = round($paidByMonth[$key] ?? 0, 2);
            $u = round($unpaidByMonth[$key] ?? 0, 2);
            $up = round($upcomingByMonth[$key] ?? 0, 2);
            $cell = null;
            if ($u > self::EPS) {
                $cell = $p > self::EPS ? ['state' => 'partial', 'amount' => $p, 'shortfall' => $u] : ['state' => 'missed', 'amount' => $u, 'shortfall' => $u];
                $missedMonths++;
            } elseif ($p > self::EPS) {
                $cell = ['state' => 'paid', 'amount' => $p, 'shortfall' => 0.0];
            } elseif ($up > self::EPS) {
                $cell = ['state' => 'upcoming', 'amount' => $up, 'shortfall' => 0.0];
            }
            $months[$key] = ($cell ?? ['state' => null, 'amount' => 0.0, 'shortfall' => 0.0]) + ['paid' => $p, 'due' => $u];

            // Running arrears: everything due up to this month's end (or today) still unpaid at that point,
            // so missed months carry forward and merge with later shortfalls.
            $arrears = 0.0;
            $monthStart = $key.'-01';
            if ($monthStart <= $todayStr) {
                $cut = min(Carbon::parse($monthStart)->endOfMonth()->toDateString(), $todayStr);
                foreach ($schedules as $s) {
                    if (substr((string) $s->due_date, 0, 10) > $cut) {
                        continue;
                    }
                    $sDue = round((float) $s->due_amount + (float) $s->late_fee_amount, 2);
                    $sAllocs = $installmentAllocs[$s->id] ?? collect();
                    $sOther = max(0.0, (float) $s->paid_amount - (float) $sAllocs->sum('allocated_amount'));
                    $sPaid = (float) $sAllocs->filter(fn ($a) => substr((string) $a->payment_date, 0, 10) <= $cut)->sum('allocated_amount');
                    $arrears += max(0.0, $sDue - $sOther - $sPaid);
                }
            }
            $arrears = round($arrears, 2);
            $months[$key]['arrears'] = $arrears > self::EPS ? $arrears : 0.0;
            // Final installment period of the plan, passed and not fully cleared.
            $months[$key]['last_open'] = ($expires !== null && substr($expires, 0, 7) === $key && $expires <= $todayStr && $arrears > self::EPS) ? 1 : 0;
        }

        return [
            'invoice_id' => (int) $inv->id,
            'number' => $inv->invoice_number,
            'date' => $inv->invoice_date ? substr((string) $inv->invoice_date, 0, 10) : null,
            'branch' => $inv->branch_name,
            'plan_status' => $inv->plan_status,
            'installment_amount' => $schedules->isNotEmpty() ? round((float) $schedules->first()->due_amount, 2) : 0.0,
            'inst_paid' => $paidInst,
            'inst_partial' => $partialInst,
            'inst_total' => $schedules->count(),
            'last_due' => $lastDue,
            'expires' => $expires,
            'months' => $months,
            'payable' => round((float) $inv->grand_total, 2),
            'paid' => round((float) $inv->amount_paid, 2),
            'due' => round($totalDue, 2),
            'outstanding' => round($outstanding, 2),
            'due_inst' => $dueInst,
            'missed_months' => $missedMonths,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows  invoice rows
     * @param  list<string>  $monthKeys
     * @return array{months: array<string, array{paid: float, due: float}>, payable: float, paid: float, due: float, outstanding: float, due_inst: int, missed_months: int}
     */
    private function sumRows(array $rows, array $monthKeys): array
    {
        $months = array_fill_keys($monthKeys, ['paid' => 0.0, 'due' => 0.0, 'arrears' => 0.0, 'last_open' => 0]);
        $t = ['payable' => 0.0, 'paid' => 0.0, 'due' => 0.0, 'outstanding' => 0.0, 'due_inst' => 0, 'missed_months' => 0];
        foreach ($rows as $r) {
            foreach ($monthKeys as $key) {
                $months[$key]['paid'] = round($months[$key]['paid'] + $r['months'][$key]['paid'], 2);
                $months[$key]['due'] = round($months[$key]['due'] + $r['months'][$key]['due'], 2);
                $months[$key]['arrears'] = round($months[$key]['arrears'] + ($r['months'][$key]['arrears'] ?? 0), 2);
                $months[$key]['last_open'] += $r['months'][$key]['last_open'] ?? 0;
            }
            foreach (array_keys($t) as $field) {
                $t[$field] += $r[$field];
            }
        }
        foreach (['payable', 'paid', 'due', 'outstanding'] as $f) {
            $t[$f] = round($t[$f], 2);
        }

        return ['months' => $months] + $t;
    }

    /**
     * @param  list<array<string, mixed>>  $customers
     * @return array<string, mixed>
     */
    public function grandTotals(array $customers, array $filters): array
    {
        $keys = array_column($this->months($filters), 'key');

        return $this->sumRows($customers, $keys) + ['customers' => count(array_unique(array_column($customers, 'id'))), 'invoices' => array_sum(array_map(fn ($c) => count($c['invoices']), $customers))];
    }

    // ----------------------------------------------------------------- credit

    /**
     * Credit-sale invoices (sale_type = credit) grouped by invoice branch, then customer.
     * Per month: amount received in the month, and the running unpaid balance at month end
     * (invoice total - amount paid up to that date). The credit due-date month is flagged when
     * that date has passed and the invoice was still not cleared.
     *
     * @return array{groups: array<int, array<string, mixed>>, grand: array<string, mixed>}
     */
    public function credit(array $filters, int $limit = 5000): array
    {
        [$start, $end] = $this->rangeBounds($filters);
        $todayStr = Carbon::today()->toDateString();
        $monthKeys = array_column($this->months($filters), 'key');

        $q = DB::table('sales_invoices as si')
            ->join('customers as c', 'c.id', '=', 'si.customer_id')
            ->leftJoin('locations as ib', 'ib.id', '=', 'si.branch_id')
            ->whereNull('si.deleted_at')
            ->whereNotIn('si.status', ['draft', 'cancelled', 'returned'])
            ->where('si.sale_type', 'credit')
            ->whereDate('si.invoice_date', '<=', $end);

        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
            $q->whereIn('si.branch_id', $user->accessibleLocationIds() ?: [0]);
        }
        if ($filters['branch_ids'] !== []) {
            $allowed = array_values(array_filter($filters['branch_ids'], fn (int $id) => $this->branchAllowed($id)));
            $allowed === [] ? $q->whereRaw('1 = 0') : $q->whereIn('si.branch_id', $allowed);
        } elseif ($this->contextLocationId() !== null) {
            $q->where('si.branch_id', $this->contextLocationId());
        }
        if ($filters['customer_id'] !== '') {
            $q->where('si.customer_id', (int) $filters['customer_id']);
        }
        if ($filters['q'] !== '') {
            $kw = '%'.$filters['q'].'%';
            $q->where(fn ($w) => $w->where('c.name', 'like', $kw)->orWhere('c.customer_code', 'like', $kw)->orWhere('c.phone', 'like', $kw));
        }
        if (! $filters['include_paid']) {
            $q->whereRaw('(si.grand_total - si.amount_paid) > '.self::EPS);
        }
        if ($filters['status'] !== 'all') {
            // "has missed" / "overdue": credit due date passed and still unpaid
            $q->whereRaw('(si.grand_total - si.amount_paid) > '.self::EPS)->whereNotNull('si.credit_due_date')->whereDate('si.credit_due_date', '<', $todayStr);
        }
        if ($filters['collector_id'] !== '') {
            $q->whereExists(function ($s) use ($filters, $start, $end): void {
                $s->selectRaw('1')->from('payment_allocations as pa')->join('payments as pay', 'pay.id', '=', 'pa.payment_id')
                    ->whereColumn('pa.sales_invoice_id', 'si.id')->where('pay.direction', 'inbound')
                    ->whereDate('pay.payment_date', '>=', $start)->whereDate('pay.payment_date', '<=', $end);
                $filters['collector_id'] === 'unknown' ? $s->whereNull('pay.recorded_by') : $s->where('pay.recorded_by', (int) $filters['collector_id']);
            });
        }

        $q->orderBy('ib.name')->orderBy('si.branch_id');
        if ($filters['sort'] === 'due') {
            $q->orderByRaw('(si.grand_total - si.amount_paid) DESC');
        }
        $invoices = $q->orderBy($filters['sort'] === 'code' ? 'c.customer_code' : 'c.name')->orderBy('c.id')->orderBy('si.invoice_date')->orderBy('si.id')
            ->limit($limit)
            ->get(['si.id', 'si.invoice_number', 'si.invoice_date', 'si.credit_due_date', 'si.grand_total', 'si.amount_paid', 'si.branch_id', 'ib.name as branch_name',
                'c.id as customer_id', 'c.customer_code', 'c.name as customer_name', 'c.phone']);

        $allocs = $invoices->isEmpty() ? collect() : DB::table('payment_allocations as pa')
            ->join('payments as pay', 'pay.id', '=', 'pa.payment_id')
            ->whereIn('pa.sales_invoice_id', $invoices->pluck('id')->all())->where('pay.direction', 'inbound')
            ->get(['pa.sales_invoice_id as invoice_id', 'pa.allocated_amount', 'pay.payment_date', 'pay.recorded_by'])
            ->groupBy('invoice_id');

        $collector = $filters['collector_id'];
        $groups = [];
        foreach ($invoices as $inv) {
            $rows = $allocs[$inv->id] ?? collect();
            $payable = round((float) $inv->grand_total, 2);
            $paidNow = round((float) $inv->amount_paid, 2);
            $invDate = substr((string) $inv->invoice_date, 0, 10);
            $dueDate = $inv->credit_due_date ? substr((string) $inv->credit_due_date, 0, 10) : null;

            $months = [];
            foreach ($monthKeys as $key) {
                $paid = (float) $rows->filter(function ($a) use ($key, $collector) {
                    if (substr((string) $a->payment_date, 0, 7) !== $key) {
                        return false;
                    }

                    return $collector === '' || ($collector === 'unknown' ? $a->recorded_by === null : (int) $a->recorded_by === (int) $collector);
                })->sum('allocated_amount');
                $arrears = 0.0;
                $monthStart = $key.'-01';
                if ($monthStart <= $todayStr) {
                    $cut = min(Carbon::parse($monthStart)->endOfMonth()->toDateString(), $todayStr);
                    if ($invDate <= $cut) {
                        $paidAfter = (float) $rows->filter(fn ($a) => substr((string) $a->payment_date, 0, 10) > $cut)->sum('allocated_amount');
                        $arrears = max(0.0, $payable - max(0.0, $paidNow - $paidAfter));
                    }
                }
                $arrears = round($arrears, 2);
                $months[$key] = [
                    'paid' => round($paid, 2),
                    'arrears' => $arrears > self::EPS ? $arrears : 0.0,
                    'due_open' => ($dueDate !== null && substr($dueDate, 0, 7) === $key && $dueDate <= $todayStr && $arrears > self::EPS) ? 1 : 0,
                    'sold' => substr($invDate, 0, 7) === $key ? 1 : 0,
                ];
            }

            $row = [
                'invoice_id' => (int) $inv->id, 'number' => $inv->invoice_number, 'date' => $invDate, 'due_date' => $dueDate,
                'overdue' => $dueDate !== null && $dueDate < $todayStr && $payable - $paidNow > self::EPS,
                'payable' => $payable, 'paid' => $paidNow, 'due' => round(max(0.0, $payable - $paidNow), 2), 'months' => $months,
            ];

            $bid = (int) $inv->branch_id;
            $cid = (int) $inv->customer_id;
            $groups[$bid]['id'] = $bid;
            $groups[$bid]['name'] = $inv->branch_name;
            $groups[$bid]['customers'][$cid] ??= ['id' => $cid, 'code' => $inv->customer_code, 'name' => $inv->customer_name, 'phone' => $inv->phone, 'invoices' => []];
            $groups[$bid]['customers'][$cid]['invoices'][] = $row;
        }

        $all = [];
        $out = [];
        foreach ($groups as $bid => $g) {
            $customers = [];
            foreach ($g['customers'] as $c) {
                $c += $this->sumCredit($c['invoices'], $monthKeys);
                $customers[] = $c;
                $all[] = $c;
            }
            $out[$bid] = ['id' => $g['id'], 'name' => $g['name'], 'customers' => $customers, 'invoices' => array_sum(array_map(fn ($c) => count($c['invoices']), $customers))]
                + $this->sumCredit($customers, $monthKeys);
        }

        return [
            'groups' => $out,
            'grand' => $this->sumCredit($all, $monthKeys) + ['customers' => count(array_unique(array_column($all, 'id'))), 'invoices' => array_sum(array_map(fn ($c) => count($c['invoices']), $all))],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $monthKeys
     * @return array<string, mixed>
     */
    private function sumCredit(array $rows, array $monthKeys): array
    {
        $months = array_fill_keys($monthKeys, ['paid' => 0.0, 'arrears' => 0.0, 'due_open' => 0, 'sold' => 0]);
        $t = ['payable' => 0.0, 'paid' => 0.0, 'due' => 0.0];
        foreach ($rows as $r) {
            foreach ($monthKeys as $key) {
                $months[$key]['paid'] = round($months[$key]['paid'] + $r['months'][$key]['paid'], 2);
                $months[$key]['arrears'] = round($months[$key]['arrears'] + $r['months'][$key]['arrears'], 2);
                $months[$key]['due_open'] += $r['months'][$key]['due_open'];
                $months[$key]['sold'] += $r['months'][$key]['sold'];
            }
            foreach (array_keys($t) as $f) {
                $t[$f] = round($t[$f] + $r[$f], 2);
            }
        }

        return ['months' => $months] + $t;
    }
}
