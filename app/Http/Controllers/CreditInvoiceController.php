<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\SalesInvoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CreditInvoiceController extends Controller
{
    use AppliesInventoryLocationContext;

    public function index(Request $request): View
    {
        $filters = [
            'q'              => trim((string) $request->input('q', '')),
            'payment_status' => (string) $request->input('payment_status', ''),
            'overdue'        => (string) $request->input('overdue', ''),
        ];

        $baseScope = SalesInvoice::query()
            ->with(['customer', 'branch'])
            ->where('sale_type', 'credit')
            ->whereIn('status', ['posted', 'active']);

        $this->scopeContextBranch($baseScope);

        $query = (clone $baseScope)
            ->when($filters['q'] !== '', function ($q) use ($filters): void {
                $term = '%'.addcslashes($filters['q'], '%_\\').'%';
                $q->where(function ($sub) use ($term): void {
                    $sub->where('invoice_number', 'like', $term)
                        ->orWhereHas('customer', fn ($cq) => $cq
                            ->where('name', 'like', $term)
                            ->orWhere('customer_code', 'like', $term)
                            ->orWhere('phone', 'like', $term));
                });
            })
            ->when($filters['payment_status'] !== '', fn ($q) => $q->where('payment_status', $filters['payment_status']))
            ->when($filters['overdue'] === '1', fn ($q) => $q->where('credit_due_date', '<=', now()->toDateString())->where('payment_status', '!=', 'paid'))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        $invoices = $query
            ->paginate($this->resolvePerPage($request, 20))
            ->withQueryString();

        // KPIs from unfiltered base scope
        $kpiScope = clone $baseScope;

        $today = now()->toDateString();

        $kpis = [
            'total'          => (clone $kpiScope)->count(),
            'unpaid'         => (clone $kpiScope)->where('payment_status', '!=', 'paid')->count(),
            'overdue'        => (clone $kpiScope)->where('credit_due_date', '<', $today)->where('payment_status', '!=', 'paid')->count(),
            'total_due'      => (float) (clone $kpiScope)->where('payment_status', '!=', 'paid')->sum('amount_due'),
            'filtered'       => $invoices->total(),
        ];

        return view('credit.index', compact('invoices', 'filters', 'kpis'));
    }
}
