<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\OwnerCapitalWallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SupplierPaymentRegisterController extends Controller
{
    use AppliesInventoryLocationContext;

    public function index(Request $request): View
    {
        $filters = [
            'po_q' => trim((string) $request->input('po_q', '')),
            'supplier_id' => $request->input('supplier_id'),
        ];

        $payableScope = PurchaseOrder::query()
            ->with(['supplier', 'destinationLocation', 'payments'])
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereRaw(
                'purchase_orders.total_amount > COALESCE((SELECT SUM(amount + COALESCE(settlement_discount, 0)) FROM purchase_order_payments WHERE purchase_order_id = purchase_orders.id), 0) + purchase_orders.additional_cost_amount',
            );

        $this->scopeContextDestination($payableScope);

        $payableScope
            ->when($filters['po_q'] !== '', function ($query) use ($filters): void {
                $term = '%'.addcslashes($filters['po_q'], '%_\\').'%';
                $query->where('po_number', 'like', $term);
            })
            ->when(
                $filters['supplier_id'] !== null && $filters['supplier_id'] !== '',
                fn ($q) => $q->where('supplier_id', (int) $filters['supplier_id']),
            );

        $orders = (clone $payableScope)
            ->latest('ordered_at')
            ->latest('id')
            ->paginate($this->resolvePerPage($request, 25))
            ->withQueryString();

        $selectedPo = null;
        $paymentWallets = collect();
        $ownerCapitalWalletCode = OwnerCapitalWallet::code();

        $open = $request->query('purchase_order');
        if (is_string($open) && ctype_digit($open)) {
            $candidate = PurchaseOrder::query()
                ->with(['supplier', 'destinationLocation', 'payments'])
                ->whereKey((int) $open)
                ->first();

            if ($candidate && Gate::allows('view', $candidate)) {
                $branchOk = true;
                if ($this->contextLocationId() !== null) {
                    $branchOk = (int) $candidate->destination_location_id === $this->contextLocationId();
                } elseif (($ids = $this->accessibleLocationIds()) !== []) {
                    $branchOk = in_array((int) $candidate->destination_location_id, $ids, true);
                }

                $due = $candidate->amountDue();
                if (
                    $branchOk
                    && ! in_array($candidate->status, ['draft', 'cancelled'], true)
                    && $due > 0.009
                ) {
                    $selectedPo = $candidate;
                    $ownerWallet = OwnerCapitalWallet::activeForLocation((int) $candidate->destination_location_id);
                    $paymentWallets = $ownerWallet
                        ? collect([$ownerWallet])->values()
                        : collect();
                }
            }
        }

        $suppliers = Supplier::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('supplier-payment-register.index', compact(
            'orders',
            'filters',
            'suppliers',
            'selectedPo',
            'paymentWallets',
            'ownerCapitalWalletCode',
        ));
    }
}
