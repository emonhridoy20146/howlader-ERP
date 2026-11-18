<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\SalesInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InstallmentPlanController extends Controller
{
    use AppliesInventoryLocationContext;

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'status' => (string) $request->input('status', ''),
        ];

        $plans = InstallmentPlan::query()
            ->with(['salesInvoice.customer', 'salesInvoice.branch', 'schedules'])
            ->when($this->contextLocationId() !== null, function ($query): void {
                $branchId = $this->contextLocationId();
                $query->whereHas('salesInvoice', fn ($q) => $q->where('branch_id', $branchId));
            })
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('installment_plans.id', 'like', "%{$keyword}%")
                        ->orWhere('installment_plans.sales_invoice_id', 'like', "%{$keyword}%")
                        ->orWhere('installment_plans.customer_id', 'like', "%{$keyword}%")
                        ->orWhereHas('salesInvoice', fn ($q) => $q->where('invoice_number', 'like', "%{$keyword}%"))
                        ->orWhereHas('salesInvoice.customer', function ($q) use ($keyword): void {
                            $q->where('name', 'like', "%{$keyword}%")
                                ->orWhere('customer_code', 'like', "%{$keyword}%");
                        });
                });
            })
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->latest()
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $kpiScope = InstallmentPlan::query()
            ->when($this->contextLocationId() !== null, function ($query): void {
                $branchId = $this->contextLocationId();
                $query->whereHas('salesInvoice', fn ($q) => $q->where('branch_id', $branchId));
            });

        $overdueSchedules = InstallmentSchedule::query()
            ->where('status', 'overdue')
            ->when($this->contextLocationId() !== null, function ($query): void {
                $branchId = $this->contextLocationId();
                $query->whereHas('installmentPlan.salesInvoice', fn ($q) => $q->where('branch_id', $branchId));
            });

        $kpis = [
            'total' => (clone $kpiScope)->count(),
            'active' => (clone $kpiScope)->where('status', 'active')->count(),
            'overdue_schedules' => (clone $overdueSchedules)->count(),
            'remaining_total' => (float) (clone $kpiScope)->sum('remaining_amount'),
            'filtered' => $plans->total(),
        ];

        return view('installments.index', compact('plans', 'filters', 'kpis'));
    }

    public function show(InstallmentPlan $plan): View
    {
        if ($this->contextLocationId() !== null) {
            $plan->loadMissing('salesInvoice');
            if ((int) $plan->salesInvoice->branch_id !== (int) $this->contextLocationId()) {
                abort(404);
            }
        }

        $plan->load(['schedules', 'salesInvoice.customer', 'salesInvoice.branch']);

        $nextUnpaid = $plan->schedules->first(fn (InstallmentSchedule $s) => ! $s->isFullyPaid());
        $targetableSchedules = $plan->schedulesEligibleForTargetedPayment();

        return view('installments.show', compact('plan', 'nextUnpaid', 'targetableSchedules'));
    }

    public function store(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'down_payment' => ['required', 'numeric', 'min:0'],
            'schedules' => ['required', 'array', 'min:1'],
            'schedules.*.due_date' => ['required', 'date'],
            'schedules.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $financed = $invoice->grand_total - $data['down_payment'];

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'total_amount' => $invoice->grand_total,
            'down_payment' => $data['down_payment'],
            'financed_amount' => $financed,
            'remaining_amount' => $financed,
            'penalty_enabled' => false,
            'created_by' => auth()->id(),
        ]);

        foreach ($data['schedules'] as $i => $schedule) {
            InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => $i + 1,
                'due_date' => $schedule['due_date'],
                'due_amount' => $schedule['amount'],
            ]);
        }

        return back()->with('status', 'Installment plan created.');
    }
}
