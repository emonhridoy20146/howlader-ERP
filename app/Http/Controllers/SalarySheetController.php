<?php

namespace App\Http\Controllers;

use App\Models\SalarySheet;
use App\Services\SalarySheetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalarySheetController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'year' => (string) $request->input('year', (string) now()->year),
            'status' => (string) $request->input('status', ''),
        ];

        $salarySheets = SalarySheet::query()
            ->with(['generatedByUser', 'finalizedByUser'])
            ->when($filters['year'] !== '', fn ($q) => $q->where('period_year', $filters['year']))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->paginate($this->resolvePerPage($request, 12))
            ->withQueryString();

        $kpis = [
            'total' => SalarySheet::count(),
            'draft' => SalarySheet::where('status', SalarySheet::STATUS_DRAFT)->count(),
            'finalized' => SalarySheet::where('status', SalarySheet::STATUS_FINALIZED)->count(),
        ];

        return view('hr.salary-sheets.index', compact('salarySheets', 'filters', 'kpis'));
    }

    public function store(Request $request, SalarySheetService $service): RedirectResponse
    {
        $this->authorize('generate', SalarySheet::class);

        $data = $request->validate([
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $sheet = $service->generate(
            (int) $data['period_year'],
            (int) $data['period_month'],
            $request->user()?->id
        );

        return redirect()
            ->route('hr.salary-sheets.show', $sheet)
            ->with('status', 'Salary sheet generated for '.$sheet->periodLabel().'.');
    }

    public function show(SalarySheet $salarySheet): View
    {
        $salarySheet->load(['lines.employee.department', 'lines.payScale', 'generatedByUser', 'finalizedByUser']);

        return view('hr.salary-sheets.show', compact('salarySheet'));
    }

    public function finalize(Request $request, SalarySheet $salarySheet, SalarySheetService $service): RedirectResponse
    {
        $this->authorize('finalize', $salarySheet);

        $service->finalize($salarySheet, (int) $request->user()?->id);

        return redirect()
            ->route('hr.salary-sheets.show', $salarySheet)
            ->with('status', 'Salary sheet finalized.');
    }

    public function destroy(SalarySheet $salarySheet): RedirectResponse
    {
        if (! $salarySheet->isDraft()) {
            return redirect()->route('hr.salary-sheets.index')->with('status', 'Finalized salary sheets cannot be deleted.');
        }

        $salarySheet->delete();

        return redirect()->route('hr.salary-sheets.index')->with('status', 'Salary sheet deleted.');
    }
}
