<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Product;
use App\Services\StockCheckService;
use App\Support\CompanyPad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class StockCheckController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Product::class);

        $accessibleIds = $request->user()->accessibleLocationIds();
        if ($accessibleIds === []) {
            abort(403);
        }

        $locations = Location::query()
            ->where('is_active', true)
            ->whereIn('id', $accessibleIds)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $defaultBranchId = (string) ($request->integer('branch_id') ?: ($request->user()->branch_id ?? $locations->first()?->id ?? ''));

        return view('inventory.stock-check.index', [
            'locations' => $locations,
            'defaultBranchId' => $defaultBranchId,
        ]);
    }

    public function checklist(Request $request, StockCheckService $stockCheckService): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:locations,id'],
        ]);

        $result = $stockCheckService->branchChecklist($request->user(), (int) $data['branch_id']);
        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json(['data' => $result]);
    }

    public function sessionScan(Request $request, StockCheckService $stockCheckService): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:locations,id'],
            'code' => ['required', 'string', 'max:120'],
            'checked_serial_ids' => ['nullable', 'array'],
            'checked_serial_ids.*' => ['integer'],
            'product_checked_qty' => ['nullable', 'array'],
            'product_checked_qty.*.product_id' => ['required', 'integer'],
            'product_checked_qty.*.checked_qty' => ['required', 'integer', 'min:0'],
        ]);

        $result = $stockCheckService->sessionScan(
            (string) $data['code'],
            $request->user(),
            (int) $data['branch_id'],
            $data['checked_serial_ids'] ?? [],
            $data['product_checked_qty'] ?? [],
        );

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    public function finish(Request $request, StockCheckService $stockCheckService): RedirectResponse
    {
        $this->authorize('viewAny', Product::class);

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:locations,id'],
            'checked_serial_ids' => ['nullable', 'array'],
            'checked_serial_ids.*' => ['integer'],
            'product_checked_qty' => ['nullable', 'array'],
            'product_checked_qty.*.product_id' => ['required', 'integer'],
            'product_checked_qty.*.checked_qty' => ['required', 'integer', 'min:0'],
            'extras_json' => ['nullable', 'string'],
        ]);

        $extras = [];
        if (! empty($data['extras_json'])) {
            $decoded = json_decode((string) $data['extras_json'], true);
            $extras = is_array($decoded) ? $decoded : [];
        }

        $report = $stockCheckService->buildReport(
            $request->user(),
            (int) $data['branch_id'],
            $data['checked_serial_ids'] ?? [],
            $data['product_checked_qty'] ?? [],
            $extras,
        );

        if (isset($report['error'])) {
            return redirect()
                ->route('inventory.stock-check.index', ['branch_id' => $data['branch_id']])
                ->with('error', $report['error']);
        }

        $request->session()->put('stock_check_report', $report);

        return redirect()->route('inventory.stock-check.report');
    }

    public function report(Request $request): View|RedirectResponse
    {
        $this->authorize('viewAny', Product::class);

        $report = $request->session()->get('stock_check_report');
        if (! is_array($report)) {
            return redirect()
                ->route('inventory.stock-check.index')
                ->with('error', 'No stock check report available. Run a check first.');
        }

        return view('inventory.stock-check.report', [
            'report' => $report,
            'companyPad' => CompanyPad::settings(),
            'reportTitle' => 'Physical stock check',
            'subtitle' => $this->stockCheckReportSubtitle($report),
            'printBackUrl' => route('inventory.stock-check.index', ['branch_id' => $report['branch']['id'] ?? null]),
            'printBackLabel' => '← Stock check',
            'showReportsHubLink' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function stockCheckReportSubtitle(array $report): string
    {
        $branch = $report['branch'] ?? [];
        $branchLabel = trim((string) ($branch['name'] ?? 'Branch'));
        if (! empty($branch['code'])) {
            $branchLabel .= ' ('.$branch['code'].')';
        }

        $generatedAt = isset($report['generated_at'])
            ? Carbon::parse($report['generated_at'])
            : now();

        return $branchLabel.' · Generated '.$generatedAt->format('Y-m-d H:i');
    }

    public function lookup(Request $request, StockCheckService $stockCheckService): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);

        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;

        $result = $stockCheckService->lookup(
            (string) $data['code'],
            $request->user(),
            $branchId,
        );

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json(['data' => $result]);
    }
}
