<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\Category;
use App\Models\DemandForecast;
use App\Models\Location;
use App\Services\DemandForecastService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ForecastController extends Controller
{
    use AppliesInventoryLocationContext;

    public function __construct(
        private readonly DemandForecastService $demandForecasts,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', DemandForecast::class);

        $monthInput = (string) $request->input('month', '');
        try {
            $forecastMonth = $monthInput !== ''
                ? Carbon::parse($monthInput)->startOfMonth()
                : Carbon::now()->addMonth()->startOfMonth();
        } catch (\Throwable) {
            $forecastMonth = Carbon::now()->addMonth()->startOfMonth();
        }

        $branchId = (string) $request->input('branch_id', '');
        $q = trim((string) $request->input('q', ''));

        $query = DemandForecast::query()
            ->with(['product:id,name,sku,category_id', 'product.category:id,name', 'location:id,code,name'])
            ->whereDate('forecast_month', $forecastMonth->toDateString())
            ->when($branchId !== '', fn ($q2) => $q2->where('location_id', (int) $branchId))
            ->tap(fn ($q2) => $this->scopeContextLocation($q2))
            ->when($q !== '', function ($q2) use ($q): void {
                $q2->where(function ($outer) use ($q): void {
                    $outer->whereHas('product', function ($pq) use ($q): void {
                        $pq->where('name', 'like', "%{$q}%")
                            ->orWhere('sku', 'like', "%{$q}%");
                    })->orWhereHas('product.category', function ($cq) use ($q): void {
                        $cq->where('name', 'like', "%{$q}%");
                    });
                });
            })
            ->orderByDesc('predicted_qty')
            ->orderBy('location_id');

        $forecasts = $query->paginate($this->resolvePerPage($request, 50))->withQueryString();

        $forecastGroups = $this->groupForecastsByCategory($forecasts->getCollection());

        $filterBranches = Location::query()
            ->where('type', 'branch')
            ->where('is_active', true)
            ->orderBy('name')
            ->when($ids = $this->accessibleLocationIds(), fn ($q2) => $q2->whereIn('id', $ids))
            ->get(['id', 'code', 'name']);

        $salesHistoryStart = $forecastMonth->copy()->subMonths(3)->startOfMonth();
        $salesHistoryEnd = $forecastMonth->copy()->subMonth()->endOfMonth();

        return view('forecasts.index', [
            'forecasts' => $forecasts,
            'forecastGroups' => $forecastGroups,
            'forecastMonth' => $forecastMonth,
            'salesHistoryStart' => $salesHistoryStart,
            'salesHistoryEnd' => $salesHistoryEnd,
            'filterBranches' => $filterBranches,
            'filters' => [
                'month' => $forecastMonth->format('Y-m'),
                'branch_id' => $branchId,
                'q' => $q,
            ],
        ]);
    }

    /**
     * @param  Collection<int, DemandForecast>  $rows
     * @return Collection<int, object{ category: ?Category, items: Collection<int, DemandForecast> }>
     */
    private function groupForecastsByCategory(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        return $rows
            ->groupBy(fn (DemandForecast $f): int => (int) ($f->product?->category_id ?? 0))
            ->map(function (Collection $items): object {
                $category = $items->first()?->product?->category;

                return (object) [
                    'category' => $category,
                    'items' => $items->sortByDesc('predicted_qty')->values(),
                ];
            })
            ->values()
            ->sortBy(function (object $group): string {
                $name = $group->category?->name;

                return $name === null || $name === ''
                    ? 'zzz' // uncategorized last
                    : mb_strtolower($name);
            })
            ->values();
    }

    public function refresh(Request $request): RedirectResponse
    {
        $this->authorize('create', DemandForecast::class);

        $monthInput = (string) $request->input('month', '');
        try {
            $forecastMonth = $monthInput !== ''
                ? Carbon::parse($monthInput)->startOfMonth()
                : Carbon::now()->addMonth()->startOfMonth();
        } catch (\Throwable) {
            $forecastMonth = Carbon::now()->addMonth()->startOfMonth();
        }

        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
        if ($branchId !== null && $branchId > 0) {
            $ids = $this->accessibleLocationIds();
            if ($ids !== [] && ! in_array($branchId, $ids, true) && ! auth()->user()?->isAdmin()) {
                abort(403);
            }
        }

        $accessible = auth()->user()?->isAdmin() ? null : $this->accessibleLocationIds();

        $count = $this->demandForecasts->refresh($forecastMonth, $branchId, $accessible);

        $monthLabel = $forecastMonth->format('F Y');
        $status = $count === 1
            ? "We refreshed one estimate for {$monthLabel} from your recent sales."
            : "We refreshed {$count} estimates for {$monthLabel} from your recent sales (each line is one product at one branch).";

        return redirect()
            ->route('forecasts.index', ['month' => $forecastMonth->format('Y-m'), 'branch_id' => $request->input('branch_id'), 'q' => $request->input('q')])
            ->with('status', $status);
    }
}
