<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GlobalSearchController extends Controller
{
    use AppliesInventoryLocationContext;

    public function __invoke(Request $request): View
    {
        $q = trim((string) $request->input('q', ''));
        $branchId = $this->contextLocationId();
        $branchScopeIds = $this->branchScopeIds();
        $data = app(GlobalSearchService::class)->search($q, $branchId, $branchScopeIds);

        return view('search.index', array_merge($data, compact('q', 'branchId')));
    }

    public function live(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $branchId = $this->contextLocationId();
        $branchScopeIds = $this->branchScopeIds();
        $service = app(GlobalSearchService::class);
        $payload = $service->search($q, $branchId, $branchScopeIds);
        $groups = $service->toLiveGroups($payload);

        return response()->json([
            'groups' => $groups,
            'hasQuery' => strlen($q) >= app(GlobalSearchService::class)->minimumQueryLength($q),
            'empty' => strlen($q) >= app(GlobalSearchService::class)->minimumQueryLength($q) && $groups === [],
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function branchScopeIds(): array
    {
        $user = auth()->user();
        if (! $user || $user->isAdmin() || $user->hasPermission('location.view-all')) {
            return [];
        }

        return $user->accessibleLocationIds();
    }
}
