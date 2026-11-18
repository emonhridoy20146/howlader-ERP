<?php

namespace App\Http\Controllers;

use App\Models\BranchMonthlyTarget;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchMonthlyTargetController extends Controller
{
    public function sync(Request $request, Location $location): RedirectResponse
    {
        $this->ensureBranch($location);
        $this->authorize('create', BranchMonthlyTarget::class);
        $this->ensureUserMayAccessLocation($request->user(), $location);

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'months' => ['required', 'array'],
            'months.*.sales_target' => ['nullable', 'numeric', 'min:0'],
            'months.*.collection_target' => ['nullable', 'numeric', 'min:0'],
        ]);

        $year = (int) $data['year'];

        DB::transaction(function () use ($location, $year, $data, $request): void {
            for ($month = 1; $month <= 12; $month++) {
                $row = $data['months'][$month] ?? $data['months'][(string) $month] ?? [];
                $salesTarget = round((float) ($row['sales_target'] ?? 0), 2);
                $collectionTarget = round((float) ($row['collection_target'] ?? 0), 2);

                if ($salesTarget <= 0 && $collectionTarget <= 0) {
                    BranchMonthlyTarget::query()
                        ->where('location_id', $location->id)
                        ->where('year', $year)
                        ->where('month', $month)
                        ->delete();

                    continue;
                }

                BranchMonthlyTarget::query()->updateOrCreate(
                    [
                        'location_id' => $location->id,
                        'year' => $year,
                        'month' => $month,
                    ],
                    [
                        'sales_target' => $salesTarget,
                        'collection_target' => $collectionTarget,
                        'created_by' => $request->user()?->id,
                    ],
                );
            }
        });

        return redirect()->route('locations.show', [
            'location' => $location,
            'tab' => 'targets',
            'target_year' => $year,
        ])->with('status', 'Monthly targets saved for '.$year.'.');
    }

    private function ensureBranch(Location $location): void
    {
        if (! in_array($location->type, ['branch', 'warehouse'], true)) {
            abort(404);
        }
    }

    private function ensureUserMayAccessLocation(?User $user, Location $location): void
    {
        if (! $user) {
            abort(403);
        }

        if ($user->isAdmin() || $user->hasPermission('location.view-all')) {
            return;
        }

        if (! in_array((int) $location->id, $user->accessibleLocationIds(), true)) {
            abort(403);
        }
    }
}
