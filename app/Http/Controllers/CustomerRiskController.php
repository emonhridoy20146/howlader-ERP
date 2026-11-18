<?php

namespace App\Http\Controllers;

use App\Exports\CustomerRiskExport;
use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\Customer;
use App\Services\CustomerRiskService;
use App\Support\CompanyPad;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class CustomerRiskController extends Controller
{
    use AppliesInventoryLocationContext;

    public function __construct(private readonly CustomerRiskService $risk) {}

    /** Reports-hub entry: sends the picked customer to the right per-customer route. */
    public function hubRedirect(Request $request, ?string $to = null): RedirectResponse
    {
        $request->validate(['customer_id' => 'required|integer|exists:customers,id']);

        $route = match ($to) {
            'print' => 'customers.risk.print',
            'pdf' => 'customers.risk.pdf',
            'excel' => 'customers.risk.excel',
            default => 'customers.risk',
        };

        return redirect()->route($route, ['customer' => $request->integer('customer_id')]);
    }

    public function show(Request $request, Customer $customer): View
    {
        $report = $this->report($customer);

        return view('customers.risk', [
            'customer' => $customer,
            'report' => $report,
            'chartData' => $report['charts'],
        ]);
    }

    public function print(Request $request, Customer $customer): View
    {
        return view('reports.exports.customer-risk-print', $this->printData($customer, true));
    }

    public function pdf(Request $request, Customer $customer): Response
    {
        $pdf = Pdf::loadView('reports.exports.customer-risk-print', $this->printData($customer, false))
            ->setPaper('a4', 'portrait');

        return $pdf->download($this->filename($customer, 'pdf'));
    }

    public function excel(Request $request, Customer $customer): BinaryFileResponse
    {
        $report = $this->report($customer);

        return Excel::download(
            new CustomerRiskExport($report, CompanyPad::settings()),
            $this->filename($customer, 'xlsx'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function report(Customer $customer): array
    {
        Gate::authorize('view-customer-risk');
        $this->authorize('view', $customer);

        $user = auth()->user();
        $accessible = $this->accessibleLocationIds();
        if ($customer->branch_id !== null && ! in_array((int) $customer->branch_id, array_map('intval', $accessible), true)) {
            abort(403, 'You do not have access to this customer\'s branch.');
        }

        $unrestricted = $this->contextLocationId() === null
            && $user !== null
            && ($user->isAdmin() || $user->hasPermission('location.view-all'));
        $branchIds = $unrestricted ? null : ($this->contextLocationId() !== null ? [$this->contextLocationId()] : array_map('intval', $accessible));

        $report = $this->risk->build($customer, Carbon::today(), $branchIds);
        $this->risk->refreshSnapshot($customer, $branchIds === null ? $report : null);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function printData(Customer $customer, bool $chrome): array
    {
        $report = $this->report($customer);

        return [
            'companyPad' => CompanyPad::settings(),
            'reportTitle' => 'Customer Profile & Risk',
            'subtitle' => $customer->name.' ('.$customer->customer_code.') · As of '.$report['as_of']->format('Y-m-d'),
            'report' => $report,
            'customer' => $customer,
            'showPrintChrome' => $chrome,
            'printBackUrl' => route('customers.risk', $customer),
            'printBackLabel' => '← Back to profile',
            'showReportsHubLink' => false,
        ];
    }

    private function filename(Customer $customer, string $ext): string
    {
        return 'customer-risk-'.$customer->customer_code.'-'.now()->format('Y-m-d').'.'.$ext;
    }
}
