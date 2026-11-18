<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\InstallmentPlan;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReturnOrder;
use App\Models\SalesInvoice;
use App\Models\SerialItem;
use App\Models\ServiceJob;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Support\GlobalSearchPages;
use App\Support\SerialLabelCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class GlobalSearchService
{
    private const int LIMIT = 10;

    /**
     * @param  array<int, int>  $branchScopeIds
     * @return array{
     *     products: Collection,
     *     customers: Collection,
     *     suppliers: Collection,
     *     invoices: Collection,
     *     purchaseOrders: Collection,
     *     serials: Collection,
     *     goodsReceipts: Collection,
     *     stockTransfers: Collection,
     *     returnOrders: Collection,
     *     serviceJobs: Collection,
     *     journalEntries: Collection,
     *     installmentPlans: Collection,
     *     brands: Collection,
     *     categories: Collection,
     *     locations: Collection,
     *     pages: list<array{label: string, subtitle: string, url: string}>,
     *     riskFirst: bool
     * }
     */
    public function search(string $q, ?int $contextBranchId, array $branchScopeIds = [], ?User $user = null): array
    {
        $user ??= auth()->user();

        $empty = [
            'products' => collect(),
            'customers' => collect(),
            'suppliers' => collect(),
            'invoices' => collect(),
            'purchaseOrders' => collect(),
            'serials' => collect(),
            'goodsReceipts' => collect(),
            'stockTransfers' => collect(),
            'returnOrders' => collect(),
            'serviceJobs' => collect(),
            'journalEntries' => collect(),
            'installmentPlans' => collect(),
            'brands' => collect(),
            'categories' => collect(),
            'locations' => collect(),
            'pages' => [],
            'riskFirst' => false,
        ];

        if (strlen($q) < $this->minimumSearchLength($q)) {
            return $empty;
        }

        $riskFirst = false;
        if ($this->can($user, 'view-customer-risk', null) && preg_match('/^risk\s+(\S.*)$/i', $q, $m) === 1) {
            $riskFirst = true;
            $q = trim($m[1]);
        }

        $kw = '%'.addcslashes($q, '%_\\').'%';

        return [
            'riskFirst' => $riskFirst,
            'products' => $this->searchProducts($kw, $user),
            'customers' => $this->searchCustomers($kw, $q, $user, $branchScopeIds),
            'suppliers' => $this->searchSuppliers($kw, $user),
            'invoices' => $this->searchInvoices($kw, $contextBranchId, $branchScopeIds, $user),
            'purchaseOrders' => $this->searchPurchaseOrders($kw, $contextBranchId, $branchScopeIds, $user),
            'serials' => $this->searchSerials($kw, $q, $contextBranchId, $branchScopeIds, $user),
            'goodsReceipts' => $this->searchGoodsReceipts($kw, $contextBranchId, $branchScopeIds, $user),
            'stockTransfers' => $this->searchStockTransfers($kw, $contextBranchId, $branchScopeIds, $user),
            'returnOrders' => $this->searchReturnOrders($kw, $contextBranchId, $branchScopeIds, $user),
            'serviceJobs' => $this->searchServiceJobs($kw, $contextBranchId, $branchScopeIds, $user),
            'journalEntries' => $this->searchJournalEntries($kw, $contextBranchId, $branchScopeIds, $user),
            'installmentPlans' => $this->searchInstallmentPlans($kw, $contextBranchId, $branchScopeIds, $user),
            'brands' => $this->searchBrands($kw, $user),
            'categories' => $this->searchCategories($kw, $user),
            'locations' => $this->searchLocations($kw, $user),
            'pages' => GlobalSearchPages::match($q, $user),
        ];
    }

    private function searchProducts(string $kw, ?User $user): Collection
    {
        if (
            ! $this->can($user, 'viewAny', Product::class)
            && ! $this->can($user, 'globalSearchView', Product::class)
        ) {
            return collect();
        }

        return Product::query()
            ->with(['inventoryBalances.location:id,name,code'])
            ->where(function ($sub) use ($kw): void {
                $sub->where('name', 'like', $kw)
                    ->orWhere('sku', 'like', $kw)
                    ->orWhere('barcode', 'like', $kw)
                    ->orWhere('model_number', 'like', $kw);
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'sku', 'name', 'is_active']);
    }

    /**
     * @param  array<int, int>  $branchScopeIds
     */
    private function searchCustomers(string $kw, string $plainQ, ?User $user, array $branchScopeIds = []): Collection
    {
        if (! $this->can($user, 'viewAny', Customer::class)) {
            return collect();
        }

        $plain = trim($plainQ);

        return Customer::query()
            ->with('riskSnapshot')
            ->where('is_active', true)
            ->when($branchScopeIds !== [], fn ($q) => $q->where(fn ($b) => $b->whereNull('branch_id')->orWhereIn('branch_id', $branchScopeIds)))
            ->where(function ($sub) use ($kw, $plain): void {
                $sub->where('name', 'like', $kw)
                    ->orWhere('customer_code', 'like', $kw)
                    ->orWhere('phone', 'like', $kw)
                    ->orWhere('phone_numbers', 'like', $kw)
                    ->orWhere('email', 'like', $kw);

                if ($plain !== '') {
                    $sub->orWhere('customer_code', '=', $plain);
                }
            })
            ->orderByRaw(
                'CASE WHEN customer_code = ? THEN 0 WHEN customer_code LIKE ? THEN 1 ELSE 2 END',
                [$plain, $plain.'%']
            )
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'phone', 'customer_code']);
    }

    public function minimumQueryLength(string $q): int
    {
        return $this->minimumSearchLength($q);
    }

    private function minimumSearchLength(string $q): int
    {
        $trimmed = trim($q);
        if ($trimmed === '') {
            return 2;
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_]{0,19}$/', $trimmed) === 1) {
            return 1;
        }

        return 2;
    }

    private function searchSuppliers(string $kw, ?User $user): Collection
    {
        if (! $this->can($user, 'viewAny', Supplier::class)) {
            return collect();
        }

        return Supplier::query()
            ->where(function ($sub) use ($kw): void {
                $sub->where('name', 'like', $kw)
                    ->orWhere('phone', 'like', $kw);
            })
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name']);
    }

    /**
     * @param  array<int, int>  $branchScopeIds
     */
    private function searchInvoices(string $kw, ?int $contextBranchId, array $branchScopeIds, ?User $user): Collection
    {
        if (! $this->can($user, 'viewAny', SalesInvoice::class)) {
            return collect();
        }

        $query = SalesInvoice::query()
            ->with('customer:id,name')
            ->where(function ($sub) use ($kw): void {
                $sub->where('invoice_number', 'like', $kw)
                    ->orWhereHas('customer', function ($c) use ($kw): void {
                        $c->where('name', 'like', $kw)
                            ->orWhere('phone', 'like', $kw);
                    });
            })
            ->latest('invoice_date');

        $this->applyBranchScope($query, 'branch_id', $contextBranchId, $branchScopeIds);

        return $query->limit(self::LIMIT)->get(['id', 'invoice_number', 'invoice_date', 'grand_total', 'customer_id', 'branch_id']);
    }

    /**
     * @param  array<int, int>  $branchScopeIds
     */
    private function searchPurchaseOrders(string $kw, ?int $contextBranchId, array $branchScopeIds, ?User $user): Collection
    {
        if (! $this->can($user, 'viewAny', PurchaseOrder::class)) {
            return collect();
        }

        $query = PurchaseOrder::query()
            ->with('supplier:id,name')
            ->where(function ($sub) use ($kw): void {
                $sub->where('po_number', 'like', $kw)
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $kw));
            })
            ->latest('ordered_at');

        $this->applyBranchScope($query, 'destination_location_id', $contextBranchId, $branchScopeIds);

        return $query->limit(self::LIMIT)->get(['id', 'po_number', 'status', 'supplier_id', 'destination_location_id']);
    }

    /**
     * @param  array<int, int>  $branchScopeIds
     */
    private function searchSerials(string $kw, string $plainQ, ?int $contextBranchId, array $branchScopeIds, ?User $user): Collection
    {
        $canViewSerials = $this->can($user, 'viewAny', SerialItem::class);
        $canProductGlobalSearch = $this->can($user, 'globalSearchView', Product::class);

        if (! $canViewSerials && ! $canProductGlobalSearch) {
            return collect();
        }

        $query = SerialItem::query()
            ->with(['product:id,name,sku', 'location:id,code,name'])
            ->where(function ($sub) use ($kw, $plainQ): void {
                $sub->where('serial_number', 'like', $kw)
                    ->orWhere('manufacturer_serial', 'like', $kw);

                $labelCode = SerialLabelCode::parseScanCode($plainQ);
                if ($labelCode !== null) {
                    $sub->orWhere('label_code', $labelCode);
                }
            });

        // Product global-search viewers may find serials at any location (then open the product).
        if (! $canProductGlobalSearch) {
            $this->applyBranchScope($query, 'current_location_id', $contextBranchId, $branchScopeIds);
        }

        return $query->latest('id')->limit(self::LIMIT)->get(['id', 'serial_number', 'manufacturer_serial', 'product_id', 'current_location_id', 'status']);
    }

    /**
     * @param  array<int, int>  $branchScopeIds
     */
    private function searchGoodsReceipts(string $kw, ?int $contextBranchId, array $branchScopeIds, ?User $user): Collection
    {
        if (! $this->can($user, 'viewAny', GoodsReceipt::class)) {
            return collect();
        }

        $query = GoodsReceipt::query()
            ->with(['supplier:id,name', 'purchaseOrder:id,po_number'])
            ->where(function ($sub) use ($kw): void {
                $sub->where('grn_number', 'like', $kw)
                    ->orWhere('delivery_note_ref', 'like', $kw)
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $kw))
                    ->orWhereHas('purchaseOrder', fn ($po) => $po->where('po_number', 'like', $kw));
            })
            ->latest('received_at');

        $this->applyBranchScope($query, 'location_id', $contextBranchId, $branchScopeIds);

        return $query->limit(self::LIMIT)->get(['id', 'grn_number', 'status', 'supplier_id', 'purchase_order_id', 'location_id', 'received_at']);
    }

    /**
     * @param  array<int, int>  $branchScopeIds
     */
    private function searchStockTransfers(string $kw, ?int $contextBranchId, array $branchScopeIds, ?User $user): Collection
    {
        if (! $this->can($user, 'viewAny', StockTransfer::class)) {
            return collect();
        }

        $query = StockTransfer::query()
            ->with(['sourceLocation:id,name,code', 'destinationLocation:id,name,code'])
            ->where(function ($sub) use ($kw): void {
                $sub->where('transfer_number', 'like', $kw)
                    ->orWhere('reason', 'like', $kw)
                    ->orWhereHas('sourceLocation', fn ($loc) => $loc->where('name', 'like', $kw)->orWhere('code', 'like', $kw))
                    ->orWhereHas('destinationLocation', fn ($loc) => $loc->where('name', 'like', $kw)->orWhere('code', 'like', $kw));
            })
            ->latest('requested_at');

        if ($contextBranchId !== null) {
            $query->where(function ($sub) use ($contextBranchId): void {
                $sub->where('source_location_id', $contextBranchId)
                    ->orWhere('destination_location_id', $contextBranchId);
            });
        } elseif ($branchScopeIds !== []) {
            $query->where(function ($sub) use ($branchScopeIds): void {
                $sub->whereIn('source_location_id', $branchScopeIds)
                    ->orWhereIn('destination_location_id', $branchScopeIds);
            });
        }

        return $query->limit(self::LIMIT)->get(['id', 'transfer_number', 'status', 'source_location_id', 'destination_location_id']);
    }

    /**
     * @param  array<int, int>  $branchScopeIds
     */
    private function searchReturnOrders(string $kw, ?int $contextBranchId, array $branchScopeIds, ?User $user): Collection
    {
        if (! $this->can($user, 'viewAny', ReturnOrder::class)) {
            return collect();
        }

        $query = ReturnOrder::query()
            ->with(['customer:id,name', 'salesInvoice:id,invoice_number'])
            ->where(function ($sub) use ($kw): void {
                $sub->where('return_number', 'like', $kw)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $kw))
                    ->orWhereHas('salesInvoice', fn ($inv) => $inv->where('invoice_number', 'like', $kw));
            })
            ->latest('return_date');

        $this->applyBranchScope($query, 'branch_id', $contextBranchId, $branchScopeIds);

        return $query->limit(self::LIMIT)->get(['id', 'return_number', 'status', 'customer_id', 'sales_invoice_id', 'branch_id', 'return_date']);
    }

    /**
     * @param  array<int, int>  $branchScopeIds
     */
    private function searchServiceJobs(string $kw, ?int $contextBranchId, array $branchScopeIds, ?User $user): Collection
    {
        if (! $this->can($user, 'viewAny', ServiceJob::class)) {
            return collect();
        }

        $query = ServiceJob::query()
            ->with(['serialItem:id,serial_number', 'customer:id,name'])
            ->where(function ($sub) use ($kw): void {
                $sub->where('job_number', 'like', $kw)
                    ->orWhere('complaint', 'like', $kw)
                    ->orWhereHas('serialItem', fn ($s) => $s->where('serial_number', 'like', $kw))
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $kw));
            })
            ->latest('intake_date');

        $this->applyBranchScope($query, 'branch_id', $contextBranchId, $branchScopeIds);

        return $query->limit(self::LIMIT)->get(['id', 'job_number', 'status', 'serial_item_id', 'customer_id', 'branch_id', 'intake_date']);
    }

    /**
     * @param  array<int, int>  $branchScopeIds
     */
    private function searchJournalEntries(string $kw, ?int $contextBranchId, array $branchScopeIds, ?User $user): Collection
    {
        if (! $this->can($user, 'viewAny', JournalEntry::class)) {
            return collect();
        }

        $query = JournalEntry::query()
            ->where(function ($sub) use ($kw): void {
                $sub->where('document_no', 'like', $kw)
                    ->orWhere('description', 'like', $kw);
            })
            ->latest('entry_date');

        $this->applyBranchScope($query, 'branch_id', $contextBranchId, $branchScopeIds);

        return $query->limit(self::LIMIT)->get(['id', 'document_no', 'description', 'status', 'entry_date', 'branch_id']);
    }

    /**
     * @param  array<int, int>  $branchScopeIds
     */
    private function searchInstallmentPlans(string $kw, ?int $contextBranchId, array $branchScopeIds, ?User $user): Collection
    {
        if (! $this->can($user, 'viewAny', InstallmentPlan::class)) {
            return collect();
        }

        $query = InstallmentPlan::query()
            ->with(['salesInvoice:id,invoice_number,customer_id', 'salesInvoice.customer:id,name'])
            ->whereHas('salesInvoice', function ($inv) use ($kw): void {
                $inv->where('invoice_number', 'like', $kw)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $kw));
            })
            ->latest('id');

        if ($contextBranchId !== null) {
            $query->whereHas('salesInvoice', fn ($inv) => $inv->where('branch_id', $contextBranchId));
        } elseif ($branchScopeIds !== []) {
            $query->whereHas('salesInvoice', fn ($inv) => $inv->whereIn('branch_id', $branchScopeIds));
        }

        return $query->limit(self::LIMIT)->get(['id', 'status', 'sales_invoice_id', 'remaining_amount']);
    }

    private function searchBrands(string $kw, ?User $user): Collection
    {
        if (! $this->can($user, 'viewAny', Brand::class)) {
            return collect();
        }

        return Brand::query()
            ->where('name', 'like', $kw)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name']);
    }

    private function searchCategories(string $kw, ?User $user): Collection
    {
        if (! $this->can($user, 'viewAny', Category::class)) {
            return collect();
        }

        return Category::query()
            ->where('name', 'like', $kw)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name']);
    }

    private function searchLocations(string $kw, ?User $user): Collection
    {
        if (! $this->can($user, 'viewAny', Location::class)) {
            return collect();
        }

        return Location::query()
            ->where(function ($sub) use ($kw): void {
                $sub->where('name', 'like', $kw)
                    ->orWhere('code', 'like', $kw);
            })
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'code', 'type']);
    }

    /**
     * @param  array<int, int>  $branchScopeIds
     */
    private function applyBranchScope($query, string $column, ?int $contextBranchId, array $branchScopeIds): void
    {
        if ($contextBranchId !== null) {
            $query->where($column, $contextBranchId);

            return;
        }

        if ($branchScopeIds !== []) {
            $query->whereIn($column, $branchScopeIds);
        }
    }

    private function can(?User $user, string $ability, ?string $modelClass): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $modelClass === null
            ? Gate::forUser($user)->check($ability)
            : Gate::forUser($user)->check($ability, $modelClass);
    }

    /**
     * @param  array{
     *     products: Collection,
     *     customers: Collection,
     *     suppliers: Collection,
     *     invoices: Collection,
     *     purchaseOrders: Collection,
     *     serials: Collection,
     *     goodsReceipts: Collection,
     *     stockTransfers: Collection,
     *     returnOrders: Collection,
     *     serviceJobs: Collection,
     *     journalEntries: Collection,
     *     installmentPlans: Collection,
     *     brands: Collection,
     *     categories: Collection,
     *     locations: Collection,
     *     pages: list<array{label: string, subtitle: string, url: string}>
     * }  $payload
     * @return list<array{label: string, items: list<array{title: string, subtitle: string, url: string}>}>
     */
    public function toLiveGroups(array $payload): array
    {
        $groups = [];

        if ($payload['pages'] !== []) {
            $groups[] = [
                'label' => 'Screens',
                'items' => collect($payload['pages'])->map(fn (array $page) => [
                    'title' => $page['label'],
                    'subtitle' => $page['subtitle'],
                    'url' => $page['url'],
                ])->all(),
            ];
        }

        if ($payload['products']->isNotEmpty()) {
            $groups[] = [
                'label' => 'Products',
                'items' => $payload['products']->map(fn ($p) => [
                    'title' => $p->name,
                    'subtitle' => $p->sku.($p->is_active ? '' : ' · deactivated'),
                    'url' => route('products.show', $p),
                    'stockByBranch' => $p->inventoryBalances
                        ->filter(fn ($b) => $b->on_hand_qty != 0 || $b->available_qty != 0)
                        ->sortByDesc('on_hand_qty')
                        ->map(fn ($b) => [
                            'branch' => $b->location?->name ?? 'Unknown',
                            'qty' => (int) $b->on_hand_qty,
                            'available' => (int) $b->available_qty,
                        ])
                        ->values()
                        ->all(),
                ])->values()->all(),
            ];
        }

        if ($payload['customers']->isNotEmpty()) {
            $canRisk = auth()->user()?->can('view-customer-risk') ?? false;
            $group = [
                'label' => 'Customers',
                'items' => $payload['customers']->map(fn ($c) => [
                    'title' => $c->name,
                    'subtitle' => trim(($c->customer_code ? $c->customer_code : '').($c->customer_code && $c->phone ? ' · ' : '').($c->phone ?? '')),
                    'url' => route('customers.show', $c),
                    'actions' => $canRisk ? [[
                        'label' => 'Risk profile: '.$c->name,
                        'url' => route('customers.risk', $c),
                        'grade' => $c->riskSnapshot?->grade,
                    ]] : [],
                ])->values()->all(),
            ];

            if (($payload['riskFirst'] ?? false) && $canRisk) {
                array_unshift($groups, $group);
            } else {
                $groups[] = $group;
            }
        }

        if ($payload['suppliers']->isNotEmpty()) {
            $groups[] = [
                'label' => 'Suppliers',
                'items' => $payload['suppliers']->map(fn ($s) => [
                    'title' => $s->name,
                    'subtitle' => '',
                    'url' => route('suppliers.edit', $s),
                ])->values()->all(),
            ];
        }

        if ($payload['brands']->isNotEmpty()) {
            $groups[] = [
                'label' => 'Brands',
                'items' => $payload['brands']->map(fn ($b) => [
                    'title' => $b->name,
                    'subtitle' => '',
                    'url' => route('brands.edit', $b),
                ])->values()->all(),
            ];
        }

        if ($payload['categories']->isNotEmpty()) {
            $groups[] = [
                'label' => 'Categories',
                'items' => $payload['categories']->map(fn ($c) => [
                    'title' => $c->name,
                    'subtitle' => '',
                    'url' => route('categories.edit', $c),
                ])->values()->all(),
            ];
        }

        if ($payload['locations']->isNotEmpty()) {
            $groups[] = [
                'label' => 'Branches',
                'items' => $payload['locations']->map(fn ($loc) => [
                    'title' => $loc->name,
                    'subtitle' => trim(($loc->code ?? '').' · '.($loc->type ?? '')),
                    'url' => route('locations.show', $loc),
                ])->values()->all(),
            ];
        }

        if ($payload['invoices']->isNotEmpty()) {
            $groups[] = [
                'label' => 'Sales invoices',
                'items' => $payload['invoices']->map(function ($inv) {
                    return [
                        'title' => $inv->invoice_number,
                        'subtitle' => ($inv->customer?->name ?? '—').' · '.($inv->invoice_date?->format('M j, Y') ?? ''),
                        'url' => route('sales.invoices.show', $inv),
                    ];
                })->values()->all(),
            ];
        }

        if ($payload['installmentPlans']->isNotEmpty()) {
            $groups[] = [
                'label' => 'Installment plans',
                'items' => $payload['installmentPlans']->map(function ($plan) {
                    $invoice = $plan->salesInvoice;

                    return [
                        'title' => $invoice?->invoice_number ?? 'Plan #'.$plan->id,
                        'subtitle' => ($invoice?->customer?->name ?? '—').' · '.str_replace('_', ' ', (string) $plan->status),
                        'url' => route('installments.show', $plan),
                    ];
                })->values()->all(),
            ];
        }

        if ($payload['purchaseOrders']->isNotEmpty()) {
            $groups[] = [
                'label' => 'Purchase orders',
                'items' => $payload['purchaseOrders']->map(function ($po) {
                    return [
                        'title' => $po->po_number,
                        'subtitle' => ($po->supplier?->name ?? '—').' · '.str_replace('_', ' ', (string) $po->status),
                        'url' => route('purchase-orders.show', $po),
                    ];
                })->values()->all(),
            ];
        }

        if ($payload['goodsReceipts']->isNotEmpty()) {
            $groups[] = [
                'label' => 'Goods receipts',
                'items' => $payload['goodsReceipts']->map(function ($grn) {
                    $po = $grn->purchaseOrder?->po_number;

                    return [
                        'title' => $grn->grn_number,
                        'subtitle' => ($grn->supplier?->name ?? '—').($po ? ' · PO '.$po : '').' · '.str_replace('_', ' ', (string) $grn->status),
                        'url' => route('goods-receipts.show', $grn),
                    ];
                })->values()->all(),
            ];
        }

        if ($payload['stockTransfers']->isNotEmpty()) {
            $groups[] = [
                'label' => 'Stock transfers',
                'items' => $payload['stockTransfers']->map(function ($transfer) {
                    $from = $transfer->sourceLocation?->code ?? $transfer->sourceLocation?->name ?? '?';
                    $to = $transfer->destinationLocation?->code ?? $transfer->destinationLocation?->name ?? '?';

                    return [
                        'title' => $transfer->transfer_number,
                        'subtitle' => $from.' → '.$to.' · '.str_replace('_', ' ', (string) $transfer->status),
                        'url' => route('transfers.show', $transfer),
                    ];
                })->values()->all(),
            ];
        }

        if ($payload['serials']->isNotEmpty()) {
            $user = auth()->user();
            $serialLinksToProduct = $user
                && $user->can('globalSearchView', Product::class)
                && ! $user->can('viewAny', SerialItem::class);

            $groups[] = [
                'label' => 'Serial numbers',
                'items' => $payload['serials']->map(function ($serial) use ($serialLinksToProduct) {
                    $product = $serial->product;
                    $location = $serial->location;
                    $locationBit = $location
                        ? trim(($location->code ? $location->code.' · ' : '').($location->name ?? ''))
                        : '';
                    $sub = ($product?->name ?? '—').' · '.str_replace('_', ' ', (string) $serial->status);
                    if ($locationBit !== '') {
                        $sub .= ' · '.$locationBit;
                    }

                    $url = $serialLinksToProduct && $product
                        ? route('products.show', $product)
                        : route('serial-items.show', $serial);

                    return [
                        'title' => $serial->serial_number,
                        'subtitle' => $sub,
                        'url' => $url,
                    ];
                })->values()->all(),
            ];
        }

        if ($payload['returnOrders']->isNotEmpty()) {
            $groups[] = [
                'label' => 'Returns',
                'items' => $payload['returnOrders']->map(function ($ret) {
                    return [
                        'title' => $ret->return_number,
                        'subtitle' => ($ret->customer?->name ?? '—').' · '.($ret->salesInvoice?->invoice_number ?? '—'),
                        'url' => route('returns.show', $ret),
                    ];
                })->values()->all(),
            ];
        }

        if ($payload['serviceJobs']->isNotEmpty()) {
            $groups[] = [
                'label' => 'Service jobs',
                'items' => $payload['serviceJobs']->map(function ($job) {
                    return [
                        'title' => $job->job_number,
                        'subtitle' => ($job->serialItem?->serial_number ?? '—').' · '.str_replace('_', ' ', (string) $job->status),
                        'url' => route('service-jobs.show', $job),
                    ];
                })->values()->all(),
            ];
        }

        if ($payload['journalEntries']->isNotEmpty()) {
            $groups[] = [
                'label' => 'Journal entries',
                'items' => $payload['journalEntries']->map(function ($entry) {
                    return [
                        'title' => $entry->document_no ?: 'Journal #'.$entry->id,
                        'subtitle' => Str::limit((string) ($entry->description ?? ''), 60).' · '.str_replace('_', ' ', (string) $entry->status),
                        'url' => route('journal-entries.show', $entry),
                    ];
                })->values()->all(),
            ];
        }

        return $groups;
    }
}
