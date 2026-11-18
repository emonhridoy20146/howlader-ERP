<?php

namespace App\Http\Controllers;

use App\Models\BranchAnnualTarget;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BranchAnnualTargetController extends Controller
{
    public function store(Request $request, Location $location): RedirectResponse
    {
        $this->ensureBranch($location);
        $this->authorize('create', BranchAnnualTarget::class);
        $this->ensureUserMayAccessLocation($request->user(), $location);

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'sales_target' => ['required', 'numeric', 'min:0'],
            'collection_target' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ((float) $data['sales_target'] <= 0 && (float) $data['collection_target'] <= 0) {
            return back()
                ->withErrors(['sales_target' => 'Set at least one target amount greater than zero.'])
                ->withInput();
        }

        BranchAnnualTarget::query()->updateOrCreate(
            [
                'location_id' => $location->id,
                'year' => (int) $data['year'],
            ],
            [
                'sales_target' => $data['sales_target'],
                'collection_target' => $data['collection_target'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ],
        );

        return $this->redirectToTargetsTab($location, (int) $data['year'])
            ->with('status', 'Annual targets saved.');
    }

    public function update(Request $request, Location $location, BranchAnnualTarget $branchAnnualTarget): RedirectResponse
    {
        $this->ensureBranch($location);
        $this->ensureTargetBelongsToLocation($location, $branchAnnualTarget);
        $this->authorize('update', $branchAnnualTarget);
        $this->ensureUserMayAccessLocation($request->user(), $location);

        $data = $request->validate([
            'sales_target' => ['required', 'numeric', 'min:0'],
            'collection_target' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ((float) $data['sales_target'] <= 0 && (float) $data['collection_target'] <= 0) {
            return back()
                ->withErrors(['sales_target' => 'Set at least one target amount greater than zero.'])
                ->withInput();
        }

        $branchAnnualTarget->update([
            'sales_target' => $data['sales_target'],
            'collection_target' => $data['collection_target'],
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->redirectToTargetsTab($location, (int) $branchAnnualTarget->year)
            ->with('status', 'Annual targets updated.');
    }

    public function destroy(Request $request, Location $location, BranchAnnualTarget $branchAnnualTarget): RedirectResponse
    {
        $this->ensureBranch($location);
        $this->ensureTargetBelongsToLocation($location, $branchAnnualTarget);
        $this->authorize('delete', $branchAnnualTarget);
        $this->ensureUserMayAccessLocation($request->user(), $location);

        $year = (int) $branchAnnualTarget->year;
        $branchAnnualTarget->delete();

        return $this->redirectToTargetsTab($location, $year)
            ->with('status', 'Annual targets removed.');
    }

    private function ensureBranch(Location $location): void
    {
        if (! in_array($location->type, ['branch', 'warehouse'], true)) {
            abort(404);
        }
    }

    private function ensureTargetBelongsToLocation(Location $location, BranchAnnualTarget $target): void
    {
        if ((int) $target->location_id !== (int) $location->id) {
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

    private function redirectToTargetsTab(Location $location, int $year): RedirectResponse
    {
        return redirect()->route('locations.show', [
            'location' => $location,
            'tab' => 'targets',
            'target_year' => $year,
        ]);
    }
}
