<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'is_active' => (string) $request->input('is_active', ''),
        ];

        $editId = $request->integer('edit');
        $editingSupplier = $editId ? Supplier::find($editId) : null;
        $suppliers = Supplier::query()
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('name', 'like', "%{$keyword}%")
                        ->orWhere('contact_person', 'like', "%{$keyword}%")
                        ->orWhere('phone', 'like', "%{$keyword}%")
                        ->orWhere('phone_numbers', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%");
                });
            })
            ->when($filters['is_active'] !== '', fn ($query) => $query->where('is_active', $filters['is_active'] === '1'))
            ->latest()
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $supplierIds = $suppliers->pluck('id');
        $purchaseOrderDueBySupplier = collect();
        if ($supplierIds->isNotEmpty()) {
            $purchaseOrderDueBySupplier = DB::table('purchase_orders as po')
                ->leftJoin(DB::raw('(select purchase_order_id, COALESCE(SUM(amount + COALESCE(settlement_discount, 0)), 0) as paid from purchase_order_payments group by purchase_order_id) as pay'), 'pay.purchase_order_id', '=', 'po.id')
                ->whereIn('po.supplier_id', $supplierIds)
                ->whereNotIn('po.status', ['draft', 'cancelled'])
                ->groupBy('po.supplier_id')
                ->selectRaw('po.supplier_id as supplier_id, SUM(GREATEST(0, po.total_amount - COALESCE(pay.paid, 0))) as balance_due')
                ->pluck('balance_due', 'supplier_id');
        }

        $totalPoPayableDue = (float) (DB::table('purchase_orders as po')
            ->leftJoin(DB::raw('(select purchase_order_id, COALESCE(SUM(amount + COALESCE(settlement_discount, 0)), 0) as paid from purchase_order_payments group by purchase_order_id) as pay'), 'pay.purchase_order_id', '=', 'po.id')
            ->whereNotIn('po.status', ['draft', 'cancelled'])
            ->selectRaw('COALESCE(SUM(GREATEST(0, po.total_amount - COALESCE(pay.paid, 0))), 0) as t')
            ->value('t') ?? 0);

        $kpis = [
            'total' => Supplier::count(),
            'active' => Supplier::where('is_active', true)->count(),
            'inactive' => Supplier::where('is_active', false)->count(),
            'filtered' => $suppliers->total(),
            'purchase_order_due_total' => $totalPoPayableDue,
        ];

        return view('suppliers.index', compact('suppliers', 'editingSupplier', 'filters', 'kpis', 'purchaseOrderDueBySupplier'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('suppliers.index', ['drawer' => 'create']);
    }

    public function show(Request $request, Supplier $supplier): View
    {
        $purchaseOrders = $supplier->purchaseOrders()
            ->with(['destinationLocation'])
            ->withSettledPaymentsSum()
            ->latest()
            ->paginate($this->resolvePerPage($request, 10))
            ->withQueryString();

        $totals = [
            'purchase_orders' => (int) $supplier->purchaseOrders()->count(),
            'purchase_orders_active' => (int) $supplier->purchaseOrders()->whereNotIn('status', ['draft', 'cancelled'])->count(),
            'purchase_orders_grand_total' => (float) ($supplier->purchaseOrders()->sum('total_amount') ?? 0),
            'purchase_orders_due' => (float) (DB::table('purchase_orders as po')
                ->leftJoin(DB::raw('(select purchase_order_id, COALESCE(SUM(amount + COALESCE(settlement_discount, 0)), 0) as paid from purchase_order_payments group by purchase_order_id) as pay'), 'pay.purchase_order_id', '=', 'po.id')
                ->where('po.supplier_id', $supplier->id)
                ->whereNotIn('po.status', ['draft', 'cancelled'])
                ->selectRaw('COALESCE(SUM(GREATEST(0, po.total_amount - COALESCE(pay.paid, 0))), 0) as t')
                ->value('t') ?? 0),
        ];

        return view('suppliers.show', compact('supplier', 'purchaseOrders', 'totals'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'phone_numbers' => ['nullable', 'array'],
            'phone_numbers.*' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $phones = collect($data['phone_numbers'] ?? [])
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values()
            ->all();
        $legacyPhone = trim((string) ($data['phone'] ?? ''));
        $data['phone_numbers'] = $phones;
        $data['phone'] = $phones[0] ?? ($legacyPhone !== '' ? $legacyPhone : null);
        $data['is_active'] = $request->boolean('is_active', true);

        Supplier::create($data);

        return redirect()->route('suppliers.index')->with('status', 'Supplier created successfully.');
    }

    public function edit(Supplier $supplier): RedirectResponse
    {
        return redirect()->route('suppliers.index', ['drawer' => 'edit', 'edit' => $supplier->id]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'phone_numbers' => ['nullable', 'array'],
            'phone_numbers.*' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $phones = collect($data['phone_numbers'] ?? [])
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values()
            ->all();
        $legacyPhone = trim((string) ($data['phone'] ?? ''));
        $data['phone_numbers'] = $phones;
        $data['phone'] = $phones[0] ?? ($legacyPhone !== '' ? $legacyPhone : null);
        $data['is_active'] = $request->boolean('is_active', true);

        $supplier->update($data);

        return redirect()->route('suppliers.index')->with('status', 'Supplier updated successfully.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->delete();

        return redirect()->route('suppliers.index')->with('status', 'Supplier deleted.');
    }
}
