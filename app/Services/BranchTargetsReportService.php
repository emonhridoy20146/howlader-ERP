<?php

namespace App\Services;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BranchTargetsReportService
{
    public function __construct(
        private readonly BranchAnnualTargetProgressService $annualProgress,
        private readonly BranchMonthlyTargetProgressService $monthlyProgress,
    ) {}

    /**
     * @return array{year: int, month: int, branch_id: string, view: string}
     */
    public function filters(Request $request): array
    {
        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);
        $view = (string) $request->input('view', 'both');

        if ($year < 2000 || $year > 2100) {
            $year = (int) now()->year;
        }
        if ($month < 1 || $month > 12) {
            $month = (int) now()->month;
        }
        if (! in_array($view, ['both', 'monthly', 'annual'], true)) {
            $view = 'both';
        }

        return [
            'year' => $year,
            'month' => $month,
            'branch_id' => (string) $request->input('branch_id', ''),
            'view' => $view,
        ];
    }

    /**
     * @return Collection<int, Location>
     */
    public function filterBranches(): Collection
    {
        $query = Location::query()
            ->where('is_active', true)
            ->whereIn('type', ['branch', 'warehouse'])
            ->orderBy('name');

        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all')) {
            $query->whereIn('id', $user->accessibleLocationIds());
        }

        return $query->get(['id', 'code', 'name', 'type']);
    }

    /**
     * @param  array{year: int, month: int, branch_id: string, view: string}  $filters
     * @return list<int>
     */
    public function locationIdsForFilters(array $filters): array
    {
        $branches = $this->filterBranches();

        if ($filters['branch_id'] !== '') {
            $id = (int) $filters['branch_id'];
            if (! $branches->contains(fn (Location $loc): bool => (int) $loc->id === $id)) {
                return [];
            }

            return [$id];
        }

        return $branches->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array{
     *     filters: array{year: int, month: int, branch_id: string, view: string},
     *     rows: Collection<int, array<string, mixed>>,
     *     kpis: array<string, float|int|null>,
     *     filterBranches: Collection<int, Location>,
     *     filterSummary: string,
     *     monthLabel: string
     * }
     */
    public function report(Request $request): array
    {
        $filters = $this->filters($request);
        $locationIds = $this->locationIdsForFilters($filters);
        $branches = $this->filterBranches()
            ->when(
                $filters['branch_id'] !== '',
                fn (Collection $c) => $c->where('id', (int) $filters['branch_id'])->values()
            );

        $showMonthly = in_array($filters['view'], ['both', 'monthly'], true);
        $showAnnual = in_array($filters['view'], ['both', 'annual'], true);

        $monthly = $showMonthly
            ? $this->monthlyProgress->forLocationIds($locationIds, $filters['year'], $filters['month'])
            : [];
        $annual = $showAnnual
            ? $this->annualProgress->forLocationIds($locationIds, $filters['year'])
            : [];

        $rows = $branches->map(function (Location $loc) use ($monthly, $annual, $showMonthly, $showAnnual): array {
            $id = (int) $loc->id;
            $m = $monthly[$id] ?? null;
            $a = $annual[$id] ?? null;

            return [
                'location' => $loc,
                'monthly' => $showMonthly ? $m : null,
                'annual' => $showAnnual ? $a : null,
                'has_target' => ($showMonthly && ($m['has_target'] ?? false))
                    || ($showAnnual && ($a['has_target'] ?? false)),
            ];
        })->values();

        $monthLabel = Carbon::create($filters['year'], $filters['month'], 1)->format('F Y');

        return [
            'filters' => $filters,
            'rows' => $rows,
            'kpis' => $this->kpis($rows, $showMonthly, $showAnnual),
            'filterBranches' => $this->filterBranches(),
            'filterSummary' => $this->filterSummary($filters, $monthLabel),
            'monthLabel' => $monthLabel,
            'showMonthly' => $showMonthly,
            'showAnnual' => $showAnnual,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int|null>
     */
    private function kpis(Collection $rows, bool $showMonthly, bool $showAnnual): array
    {
        $monthlySalesActual = 0.0;
        $monthlySalesTarget = 0.0;
        $monthlyCollectionActual = 0.0;
        $monthlyCollectionTarget = 0.0;
        $annualSalesActual = 0.0;
        $annualSalesTarget = 0.0;
        $annualCollectionActual = 0.0;
        $annualCollectionTarget = 0.0;
        $monthlyCount = 0;
        $annualCount = 0;

        foreach ($rows as $row) {
            if ($showMonthly && ($row['monthly']['has_target'] ?? false)) {
                $monthlyCount++;
                $monthlySalesActual += (float) $row['monthly']['sales']['actual'];
                $monthlySalesTarget += (float) $row['monthly']['sales']['target'];
                $monthlyCollectionActual += (float) $row['monthly']['collection']['actual'];
                $monthlyCollectionTarget += (float) $row['monthly']['collection']['target'];
            }
            if ($showAnnual && ($row['annual']['has_target'] ?? false)) {
                $annualCount++;
                $annualSalesActual += (float) $row['annual']['sales']['actual'];
                $annualSalesTarget += (float) $row['annual']['sales']['target'];
                $annualCollectionActual += (float) $row['annual']['collection']['actual'];
                $annualCollectionTarget += (float) $row['annual']['collection']['target'];
            }
        }

        return [
            'branches' => $rows->count(),
            'monthly_with_targets' => $monthlyCount,
            'annual_with_targets' => $annualCount,
            'monthly_sales_actual' => round($monthlySalesActual, 2),
            'monthly_sales_target' => round($monthlySalesTarget, 2),
            'monthly_sales_pct' => $monthlySalesTarget > 0 ? min(999.99, round(($monthlySalesActual / $monthlySalesTarget) * 100, 2)) : null,
            'monthly_collection_actual' => round($monthlyCollectionActual, 2),
            'monthly_collection_target' => round($monthlyCollectionTarget, 2),
            'monthly_collection_pct' => $monthlyCollectionTarget > 0 ? min(999.99, round(($monthlyCollectionActual / $monthlyCollectionTarget) * 100, 2)) : null,
            'annual_sales_actual' => round($annualSalesActual, 2),
            'annual_sales_target' => round($annualSalesTarget, 2),
            'annual_sales_pct' => $annualSalesTarget > 0 ? min(999.99, round(($annualSalesActual / $annualSalesTarget) * 100, 2)) : null,
            'annual_collection_actual' => round($annualCollectionActual, 2),
            'annual_collection_target' => round($annualCollectionTarget, 2),
            'annual_collection_pct' => $annualCollectionTarget > 0 ? min(999.99, round(($annualCollectionActual / $annualCollectionTarget) * 100, 2)) : null,
        ];
    }

    /**
     * @param  array{year: int, month: int, branch_id: string, view: string}  $filters
     */
    private function filterSummary(array $filters, string $monthLabel): string
    {
        $parts = [];
        $parts[] = match ($filters['view']) {
            'monthly' => 'Monthly only · '.$monthLabel,
            'annual' => 'Annual only · '.$filters['year'],
            default => 'Monthly '.$monthLabel.' + Annual '.$filters['year'],
        };

        if ($filters['branch_id'] !== '') {
            $branch = $this->filterBranches()->firstWhere('id', (int) $filters['branch_id']);
            $parts[] = $branch ? 'Branch: '.$branch->name : 'Branch #'.$filters['branch_id'];
        } else {
            $parts[] = 'All accessible branches';
        }

        return implode(' · ', $parts);
    }
}
