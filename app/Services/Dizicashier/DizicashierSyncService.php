<?php

namespace App\Services\Dizicashier;

use App\Dizicashier\DizicashierHttpClient;
use App\Models\AccountsReceivableLedger;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SaleLineSerial;
use App\Models\SalesInvoice;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Services\GoodsReceiptPostingService;
use App\Services\InstallmentAdvanceService;
use App\Services\InstallmentInvoiceSyncService;
use App\Services\InventoryBalanceService;
use App\Services\JournalService;
use App\Services\SalesInvoiceBranchChangeService;
use App\Services\SalesPostingService;
use App\Services\SerialOnHandBackfillService;
use App\Support\LocationCode;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DizicashierSyncService
{
    private ?Brand $defaultBrand = null;

    private ?Category $defaultCategory = null;

    private ?Location $fallbackLocation = null;

    /** @var array<string, string>|null costCenterId => wireHouseId */
    private ?array $costCenterWarehouseMap = null;

    public function __construct(
        private readonly DizicashierHttpClient $client,
        private readonly GoodsReceiptPostingService $goodsReceiptPostingService,
        private readonly InventoryBalanceService $inventoryBalanceService,
        private readonly JournalService $journalService,
        private readonly DocumentNumberGenerator $documentNumbers,
        private readonly SerialOnHandBackfillService $serialOnHandBackfillService,
        private readonly SalesPostingService $salesPostingService,
    ) {}

    /**
     * @param  callable(string): void  $log
     * @param  list<string>  $only  steps: locations, products, customers, suppliers, transactions, transfers, stock
     */
    public function run(
        callable $log,
        bool $dryRun,
        ?Carbon $since,
        array $only,
        ?string $postmanCollectionPath = null,
    ): void {
        if ($postmanCollectionPath !== null && $postmanCollectionPath !== '') {
            Config::set('dizicashier.postman_collection_path', $postmanCollectionPath);
        }

        $actorId = $this->resolveImportActorUserId();

        $only = $only === [] ? ['locations', 'products', 'customers', 'suppliers', 'transactions', 'transfers'] : $only;

        if (in_array('locations', $only, true)) {
            $this->syncLocations($log, $dryRun);
        }
        if (in_array('products', $only, true)) {
            $this->syncProducts($log, $dryRun);
        }
        if (in_array('customers', $only, true)) {
            $this->syncCustomers($log, $dryRun);
        }
        if (in_array('suppliers', $only, true)) {
            $this->syncSuppliers($log, $dryRun);
        }
        if (in_array('transactions', $only, true)) {
            $this->syncTransactionsChronological($log, $dryRun, $since, $actorId);
        }
        if ($this->shouldRunTransfers($only)) {
            $this->syncStockTransfersFromDizicashier($log, $dryRun, $since, $actorId);
        }

        if ($this->shouldReconcileStockFromApi($only)) {
            $this->reconcileStockFromDizicashierApi($log, $dryRun, $actorId);
        }
    }

    /**
     * Force-update local stock from Dizicashier GET /api/stock/ only (no products, locations, or transactions).
     * Reconciles {@see StockLedger} and inventory balances; runs serial on-hand backfill for serialized items.
     *
     * @param  callable(string): void  $log
     */
    public function syncStockFromDizicashierApi(callable $log, bool $dryRun): void
    {
        $actorId = $this->resolveImportActorUserId();
        $this->reconcileStockFromDizicashierApi($log, $dryRun, $actorId);
    }

    /**
     * @param  list<string>  $only
     */
    private function shouldReconcileStockFromApi(array $only): bool
    {
        if (in_array('stock', $only, true)) {
            return true;
        }

        if (in_array('products', $only, true) || in_array('transactions', $only, true)) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $only
     */
    private function shouldRunTransfers(array $only): bool
    {
        return in_array('transfers', $only, true) || in_array('transactions', $only, true);
    }

    /**
     * Import inter-warehouse stock transfers from Dizicashier (GET /api/stock/gettransfer + detail).
     * Creates {@see StockTransfer} + {@see StockTransferLine} rows (status received) and matching
     * transfer_issue / transfer_receive ledger rows for non-serialized products.
     */
    private function syncStockTransfersFromDizicashier(callable $log, bool $dryRun, ?Carbon $since, int $actorId): void
    {
        if (! Schema::hasColumn('stock_transfers', 'dizicashier_uuid')) {
            $log('Dizicashier transfers: skipped (add stock_transfers.dizicashier_uuid — run migrations).');

            return;
        }

        $rows = $this->paginateModelList('/api/stock/gettransfer', [
            'sort' => 'Date-asc',
        ]);
        $log('Dizicashier stock transfers (list): '.count($rows).' rows.');

        if ($dryRun) {
            return;
        }

        $imported = 0;
        foreach ($rows as $listRow) {
            if (! is_array($listRow)) {
                continue;
            }
            $transferUuid = (string) ($listRow['Id'] ?? '');
            if ($transferUuid === '') {
                continue;
            }
            if (StockTransfer::query()->where('dizicashier_uuid', $transferUuid)->exists()) {
                continue;
            }

            $at = $this->parseApiDate($listRow['Date'] ?? null);
            if ($since !== null && $at->lt($since)) {
                continue;
            }

            try {
                $decoded = $this->client->getDecoded('/api/Stock/getstocktransferbyid', ['id' => $transferUuid]);
                $d = $decoded['Data'] ?? [];
                if (! is_array($d)) {
                    continue;
                }

                $fromUuid = isset($d['FromWireHouseId']) && is_string($d['FromWireHouseId']) ? trim($d['FromWireHouseId']) : '';
                $toUuid = isset($d['ToWireHouseId']) && is_string($d['ToWireHouseId']) ? trim($d['ToWireHouseId']) : '';
                $fromName = trim((string) ($listRow['From'] ?? ''));
                $toName = trim((string) ($listRow['To'] ?? ''));

                $fromLoc = $this->resolveLocationForDizicashierTransfer($fromUuid, $fromName);
                $toLoc = $this->resolveLocationForDizicashierTransfer($toUuid, $toName);
                if (! $fromLoc || ! $toLoc || $fromLoc->id === $toLoc->id) {
                    $log('Skip transfer '.$transferUuid.': could not resolve distinct source/destination locations (From='.$fromName.', To='.$toName.').');

                    continue;
                }

                $items = $d['StockTransferItems'] ?? [];
                if (! is_array($items) || $items === []) {
                    continue;
                }

                $lines = [];
                foreach ($items as $item) {
                    if (! is_array($item) || ($item['IsDeleted'] ?? false) === true) {
                        continue;
                    }
                    $itemUuid = (string) ($item['ItemId'] ?? '');
                    if ($itemUuid === '') {
                        continue;
                    }
                    $qty = max(0, (int) ($item['Quantity'] ?? 0));
                    if ($qty <= 0) {
                        continue;
                    }
                    $product = Product::query()->where('dizicashier_uuid', $itemUuid)->first();
                    if (! $product) {
                        continue;
                    }
                    if ($product->is_serialized) {
                        $log('Skip transfer '.$transferUuid.' line '.$itemUuid.': serialized items are not applied via transfer import.');

                        continue;
                    }
                    $lines[] = ['product' => $product, 'qty' => $qty];
                }

                if ($lines === []) {
                    continue;
                }

                DB::transaction(function () use ($lines, $fromLoc, $toLoc, $actorId, $at, $transferUuid, &$imported): void {
                    $transferNumber = $this->documentNumbers->next(
                        DocumentNumberGenerator::TYPE_STOCK_TRANSFER,
                        'TRF-DZ',
                        $at,
                        'Ymd',
                        5,
                    );

                    $transfer = StockTransfer::query()->create([
                        'dizicashier_uuid' => $transferUuid,
                        'transfer_number' => $transferNumber,
                        'source_location_id' => $fromLoc->id,
                        'destination_location_id' => $toLoc->id,
                        'status' => 'received',
                        'reason' => 'Imported from Dizicashier',
                        'requested_by' => $actorId,
                        'approved_by' => $actorId,
                        'source_approved_by' => $actorId,
                        'destination_approved_by' => $actorId,
                        'issued_by' => $actorId,
                        'received_by' => $actorId,
                        'requested_at' => $at,
                        'approved_at' => $at,
                        'source_approved_at' => $at,
                        'destination_approved_at' => $at,
                        'issued_at' => $at,
                        'received_at' => $at,
                    ]);

                    foreach ($lines as $line) {
                        $product = $line['product'];
                        $qty = $line['qty'];
                        $unitCost = (float) ($product->default_purchase_price ?? 0);

                        StockTransferLine::query()->create([
                            'stock_transfer_id' => $transfer->id,
                            'product_id' => $product->id,
                            'qty_requested' => $qty,
                            'qty_issued' => $qty,
                            'qty_received' => $qty,
                        ]);

                        StockLedger::query()->create([
                            'event_time' => $at,
                            'product_id' => $product->id,
                            'serial_item_id' => null,
                            'location_id' => $fromLoc->id,
                            'movement_type' => 'transfer_issue',
                            'quantity_delta' => -1 * $qty,
                            'unit_cost' => $unitCost > 0 ? $unitCost : null,
                            'reference_type' => StockTransfer::LEDGER_REFERENCE_TYPE,
                            'reference_id' => $transfer->id,
                            'actor_user_id' => $actorId,
                        ]);
                        StockLedger::query()->create([
                            'event_time' => $at,
                            'product_id' => $product->id,
                            'serial_item_id' => null,
                            'location_id' => $toLoc->id,
                            'movement_type' => 'transfer_receive',
                            'quantity_delta' => $qty,
                            'unit_cost' => $unitCost > 0 ? $unitCost : null,
                            'reference_type' => StockTransfer::LEDGER_REFERENCE_TYPE,
                            'reference_id' => $transfer->id,
                            'actor_user_id' => $actorId,
                        ]);

                        $this->inventoryBalanceService->recalculate($product->id, $fromLoc->id);
                        $this->inventoryBalanceService->recalculate($product->id, $toLoc->id);
                    }

                    $imported++;
                });
            } catch (Throwable $e) {
                $log('Skip transfer '.$transferUuid.': '.$e->getMessage());
            }
        }

        $log('Dizicashier stock transfers newly imported: '.$imported.'.');
    }

    private function resolveLocationForDizicashierTransfer(string $wireHouseUuid, string $warehouseNameFromList): ?Location
    {
        if ($wireHouseUuid !== '' && ! $this->isBlankDizicashierUuid($wireHouseUuid)) {
            $byUuid = $this->resolveLocationUuid($wireHouseUuid);
            if ($byUuid) {
                return $byUuid;
            }
        }

        $name = trim($warehouseNameFromList);
        if ($name === '') {
            return null;
        }

        return Location::query()->where('name', $name)->first()
            ?? Location::query()->whereRaw('LOWER(TRIM(name)) = ?', [Str::lower($name)])->first();
    }

    private function isBlankDizicashierUuid(string $uuid): bool
    {
        return strtolower($uuid) === '00000000-0000-0000-0000-000000000000';
    }

    private function resolveImportActorUserId(): int
    {
        $configured = (int) (config('dizicashier.import_user_id') ?: 0);
        if ($configured > 0 && User::query()->whereKey($configured)->exists()) {
            return $configured;
        }

        $fallback = (int) (User::query()->where('is_active', true)->orderBy('id')->value('id') ?? 0);
        if ($fallback > 0) {
            return $fallback;
        }

        throw new RuntimeException('No import user: set DIZICASHIER_IMPORT_USER_ID to a valid users.id or create an active user.');
    }

    private function defaultBrand(): Brand
    {
        return $this->defaultBrand ??= Brand::query()->firstOrCreate(
            ['name' => 'Dizicashier import'],
            ['country_of_origin' => null],
        );
    }

    private function categoryForName(string $name): Category
    {
        $name = Str::limit(trim($name), 100, '');

        if ($name === '') {
            return $this->defaultCategory ??= Category::query()->firstOrCreate(
                ['name' => 'Uncategorized'],
                ['parent_id' => null, 'sort_order' => 0],
            );
        }

        return Category::query()->firstOrCreate(
            ['name' => $name],
            ['parent_id' => null, 'sort_order' => 0],
        );
    }

    private function fallbackLocation(): Location
    {
        if ($this->fallbackLocation !== null) {
            return $this->fallbackLocation;
        }

        $loc = Location::query()->where('type', 'branch')->orderBy('id')->first();
        if (! $loc) {
            throw new RuntimeException('No branch location found. Create a location before importing.');
        }

        return $this->fallbackLocation = $loc;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paginateModelList(string $path, array $baseQuery = []): array
    {
        $perPage = max(1, (int) config('dizicashier.items_per_page', 50));
        $page = 1;
        $out = [];
        while (true) {
            $query = array_merge($baseQuery, [
                'page' => $page,
                'itemsPerPage' => $perPage,
            ]);
            $decoded = $this->client->getDecoded($path, $query);
            $data = $decoded['Data'] ?? [];
            $list = $data['ModelList'] ?? [];
            if (! is_array($list) || $list === []) {
                break;
            }
            foreach ($list as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
            $total = (int) ($data['TotalItem'] ?? 0);
            if ($total > 0 && count($out) >= $total) {
                break;
            }
            if (count($list) < $perPage) {
                break;
            }
            $page++;
            if ($page > 5000) {
                break;
            }
        }

        return $out;
    }

    private function syncLocations(callable $log, bool $dryRun): void
    {
        $rows = $this->paginateModelList('/api/WireHouse', ['sort' => 'Name-asc']);
        $log('Wirehouses: '.count($rows).' rows.');
        if ($dryRun) {
            return;
        }
        foreach ($rows as $row) {
            $uuid = (string) ($row['Id'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $name = Str::limit((string) ($row['Name'] ?? 'Warehouse'), 100, '');
            $ignoreId = Location::query()->where('dizicashier_uuid', $uuid)->value('id');
            $code = LocationCode::suggestFromName($name, $ignoreId !== null ? (int) $ignoreId : null);
            Location::query()->updateOrCreate(
                ['dizicashier_uuid' => $uuid],
                [
                    'code' => $code,
                    'name' => $name,
                    'type' => 'branch',
                    'address' => null,
                    'phone' => null,
                    'manager_name' => null,
                    'is_active' => ! ($row['IsDeleted'] ?? false),
                ],
            );
        }
    }

    private function syncProducts(callable $log, bool $dryRun): void
    {
        $rows = $this->paginateModelList('/api/item', [
            'sort' => 'Name-asc',
            'ListFilter.IsIncludeBulkItems' => 'false',
            'ListFilter.ItemCategoryIds' => '',
        ]);
        $log('Items: '.count($rows).' rows.');
        if ($dryRun) {
            return;
        }
        $brand = $this->defaultBrand();
        foreach ($rows as $row) {
            $uuid = (string) ($row['Id'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $catName = (string) ($row['ItemCategories'] ?? '');
            $category = $this->categoryForName($catName);
            $existing = Product::query()->where('dizicashier_uuid', $uuid)->first();
            $apiSku = Str::limit(trim((string) ($row['SKU'] ?? '')), 60, '');
            if ($apiSku !== '') {
                $sku = $apiSku;
            } elseif ($existing?->sku) {
                $sku = $existing->sku;
            } else {
                $sku = Str::limit(
                    $this->documentNumbers->nextGlobalSequence(
                        DocumentNumberGenerator::TYPE_DIZICASHIER_IMPORT_PRODUCT_SKU,
                        'DZ-I',
                        6,
                    ),
                    60,
                    '',
                );
            }
            $cost = (float) ($row['WeightedAvgCost'] ?? 0);
            $price = (float) ($row['PriceIncludingTax'] ?? 0);
            Product::query()->updateOrCreate(
                ['dizicashier_uuid' => $uuid],
                [
                    'sku' => $sku,
                    'barcode' => ($b = Str::limit(trim((string) ($row['Barcode'] ?? '')), 100, '')) !== '' ? $b : null,
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    'name' => Str::limit((string) ($row['Name'] ?? 'Item'), 200, ''),
                    'model_number' => null,
                    'description' => isset($row['Description']) ? (string) $row['Description'] : null,
                    'warranty_months' => 12,
                    'default_purchase_price' => $cost,
                    'default_sale_price' => $price > 0 ? $price : $cost,
                    'reorder_level' => (int) ($row['MinimumStock'] ?? 0),
                    'unit_of_measure' => Str::limit((string) ($row['MeasurementUnit'] ?? 'unit'), 20, 'unit'),
                    'is_serialized' => (bool) ($row['IsSerialItem'] ?? false),
                    'is_active' => (bool) ($row['IsActive'] ?? true) && ! ($row['IsDeleted'] ?? false),
                ],
            );
        }
    }

    /**
     * Align local {@see StockLedger} / {@see InventoryBalance} with Dizicashier current per-warehouse
     * quantities from GET /api/stock/ (Postman: Products → stocks). Uses config dizicashier.stock_api_query;
     * add ListFilter.Quantity => '>0' there to match the Postman sample. Rows are summed by
     * (product_id, location_id) so multiple API lines that resolve to the same local branch (e.g. unmapped
     * warehouses using {@see fallbackLocation}) do not overwrite each other. Serialized products get
     * {@see SerialItem} rows via {@see SerialOnHandBackfillService}; API qty 0 rows are skipped when
     * the product is serialized (avoid bulk ledger moves vs serial units).
     */
    private function reconcileStockFromDizicashierApi(callable $log, bool $dryRun, int $actorId): void
    {
        $stockQuery = config('dizicashier.stock_api_query', []);
        $stockQuery = is_array($stockQuery) ? $stockQuery : [];
        $rows = $this->paginateModelList('/api/stock/', $stockQuery);
        $log('Dizicashier GET /api/stock/ snapshot rows: '.count($rows).'.');

        if ($dryRun) {
            return;
        }

        $skippedSerializedZero = 0;
        $skippedNotArray = 0;
        $skippedEmptyItemId = 0;
        $skippedNoLocalProduct = 0;
        $rowsUsedForAggregates = 0;

        /** @var array<string, array{product_id: int, location_id: int, target: int, cost_numerator: float, cost_qty: int}> */
        $aggregates = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skippedNotArray++;

                continue;
            }
            $itemUuid = (string) ($row['ItemId'] ?? '');
            if ($itemUuid === '') {
                $skippedEmptyItemId++;

                continue;
            }
            $product = Product::query()->where('dizicashier_uuid', $itemUuid)->first();
            if (! $product) {
                $skippedNoLocalProduct++;

                continue;
            }
            $whUuid = (string) ($row['WireHouseId'] ?? '');
            $location = $this->resolveLocationUuid($whUuid !== '' ? $whUuid : null) ?? $this->fallbackLocation();
            $lineQty = max(0, (int) ($row['Quantity'] ?? 0));

            $isSerialFromApi = (bool) ($row['IsSerialItem'] ?? $product->is_serialized);
            if ($isSerialFromApi !== (bool) $product->is_serialized) {
                $product->update(['is_serialized' => $isSerialFromApi]);
                $product->refresh();
            }

            if ($lineQty <= 0 && $product->is_serialized) {
                $skippedSerializedZero++;

                continue;
            }

            $pairKey = $product->id.'|'.$location->id;
            if (! isset($aggregates[$pairKey])) {
                $aggregates[$pairKey] = [
                    'product_id' => $product->id,
                    'location_id' => $location->id,
                    'target' => 0,
                    'cost_numerator' => 0.0,
                    'cost_qty' => 0,
                ];
            }
            $aggregates[$pairKey]['target'] += $lineQty;
            if ($lineQty > 0) {
                $wac = (float) ($row['WeightedAvgCost'] ?? 0);
                $aggregates[$pairKey]['cost_numerator'] += $wac * $lineQty;
                $aggregates[$pairKey]['cost_qty'] += $lineQty;
            }
            $rowsUsedForAggregates++;
        }

        $log(sprintf(
            'Stock reconcile prep: api_rows=%d, matched_local_product=%d, skipped_no_local_product=%d, skipped_bad_row=%d, empty_item_id=%d, serialized_qty0_skips=%d, aggregate_buckets=%d.',
            count($rows),
            $rowsUsedForAggregates,
            $skippedNoLocalProduct,
            $skippedNotArray,
            $skippedEmptyItemId,
            $skippedSerializedZero,
            count($aggregates),
        ));
        if (count($rows) > 0 && $rowsUsedForAggregates === 0) {
            $log('No stock API rows matched a local product. Import or link products first (dizicashier_uuid must equal Dizicashier ItemId), e.g. php artisan dizicash:sync --only=products');
        }

        $adjusted = 0;
        $bucketsSkippedSerialized = 0;
        $bucketsNoopDelta = 0;
        foreach ($aggregates as $pairKey => $bucket) {
            $product = Product::query()->find($bucket['product_id']);
            $location = Location::query()->find($bucket['location_id']);
            if (! $product || ! $location) {
                continue;
            }
            $target = $bucket['target'];
            if ($target <= 0 && $product->is_serialized) {
                $bucketsSkippedSerialized++;

                continue;
            }
            $unitCostForIn = $bucket['cost_qty'] > 0
                ? $bucket['cost_numerator'] / $bucket['cost_qty']
                : (float) ($product->default_purchase_price ?? 0);

            try {
                DB::transaction(function () use ($product, $location, $target, $actorId, &$adjusted, &$bucketsNoopDelta, $unitCostForIn): void {
                    $current = (int) StockLedger::query()
                        ->where('product_id', $product->id)
                        ->where('location_id', $location->id)
                        ->sum('quantity_delta');
                    $delta = $target - $current;
                    if ($delta === 0) {
                        $bucketsNoopDelta++;

                        return;
                    }
                    if ($delta > 0) {
                        StockLedger::query()->create([
                            'event_time' => now(),
                            'product_id' => $product->id,
                            'serial_item_id' => null,
                            'location_id' => $location->id,
                            'movement_type' => 'adjust_in',
                            'quantity_delta' => $delta,
                            'unit_cost' => $unitCostForIn,
                            'reference_type' => 'DizicashierStockSync',
                            'reference_id' => (int) $product->id,
                            'actor_user_id' => $actorId,
                        ]);
                    } else {
                        StockLedger::query()->create([
                            'event_time' => now(),
                            'product_id' => $product->id,
                            'serial_item_id' => null,
                            'location_id' => $location->id,
                            'movement_type' => 'adjust_out',
                            'quantity_delta' => $delta,
                            'unit_cost' => null,
                            'reference_type' => 'DizicashierStockSync',
                            'reference_id' => (int) $product->id,
                            'actor_user_id' => $actorId,
                        ]);
                    }
                    $this->inventoryBalanceService->recalculate($product->id, $location->id);
                    $adjusted++;
                });
            } catch (Throwable $e) {
                $log('Skip stock aggregate '.$pairKey.' @ '.$location->code.': '.$e->getMessage());
            }
        }

        $backfill = $this->serialOnHandBackfillService->run(false, null, null, true);
        $suffix = $skippedSerializedZero > 0
            ? '; skipped '.$skippedSerializedZero.' serialized row(s) with API qty 0 (use serial tools to reconcile).'
            : '';
        $log('Stock reconcile: adjusted '.$adjusted.' product/location pairs; serial backfill created '.$backfill['created']
            .' unit(s); removed '.$backfill['removed'].' surplus in_stock serial(s).'.$suffix);
        if ($adjusted === 0 && count($aggregates) > 0) {
            $log(sprintf(
                'Hint: %d bucket(s) already matched ledger (noop_delta=%d); skipped_serialized_buckets=%d. If you expected imports, check locations map to Dizicashier WireHouseId (dizicashier_uuid on locations).',
                count($aggregates),
                $bucketsNoopDelta,
                $bucketsSkippedSerialized,
            ));
        }
    }

    private function syncCustomers(callable $log, bool $dryRun): void
    {
        $rows = $this->paginateModelList('/api/trader/customer', ['sort' => 'Name-asc']);
        $log('Customers: '.count($rows).' rows.');
        if ($dryRun) {
            return;
        }
        foreach ($rows as $row) {
            if (($row['IsSupplier'] ?? false) === true) {
                continue;
            }
            $uuid = (string) ($row['Id'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $existing = Customer::query()->where('dizicashier_uuid', $uuid)->first();
            $apiCode = Str::limit(trim((string) ($row['Code'] ?? '')), 20, '');
            if ($apiCode !== '') {
                $code = $apiCode;
            } elseif ($existing?->customer_code) {
                $code = $existing->customer_code;
            } else {
                $code = Str::limit(
                    $this->documentNumbers->nextGlobalSequence(
                        DocumentNumberGenerator::TYPE_DIZICASHIER_IMPORT_CUSTOMER_CODE,
                        'DZ-C',
                        5,
                    ),
                    20,
                    '',
                );
            }
            $phone = trim((string) ($row['ContactNo'] ?? ''));
            if ($phone === '') {
                $phone = '00000000000';
            }
            Customer::query()->updateOrCreate(
                ['dizicashier_uuid' => $uuid],
                [
                    'customer_code' => $code,
                    'name' => Str::limit((string) ($row['Name'] ?? 'Customer'), 150, ''),
                    'phone' => Str::limit($phone, 20, ''),
                    'email' => ($e = trim((string) ($row['Email'] ?? ''))) !== '' ? Str::limit($e, 150, '') : null,
                    'address' => null,
                    'is_active' => (bool) ($row['IsActive'] ?? true),
                ],
            );
        }
    }

    private function syncSuppliers(callable $log, bool $dryRun): void
    {
        $rows = $this->paginateModelList('/api/trader/supplier', ['sort' => 'Name-asc']);
        $log('Suppliers: '.count($rows).' rows.');
        if ($dryRun) {
            return;
        }
        foreach ($rows as $row) {
            $uuid = (string) ($row['Id'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $phone = trim((string) ($row['ContactNo'] ?? ''));
            Supplier::query()->updateOrCreate(
                ['dizicashier_uuid' => $uuid],
                [
                    'name' => Str::limit((string) ($row['Name'] ?? 'Supplier'), 150, ''),
                    'contact_person' => Str::limit((string) ($row['ContactPerson'] ?? ''), 100, '') ?: null,
                    'phone' => $phone !== '' ? Str::limit($phone, 20, '') : null,
                    'email' => ($e = trim((string) ($row['Email'] ?? ''))) !== '' ? Str::limit($e, 150, '') : null,
                    'address' => null,
                    'tax_number' => null,
                    'is_active' => (bool) ($row['IsActive'] ?? true),
                ],
            );
        }
    }

    private function syncTransactionsChronological(callable $log, bool $dryRun, ?Carbon $since, int $actorId): void
    {
        $purchases = $this->paginateModelList('/api/purchase', ['sort' => 'TransactionDate-asc']);
        $sales = $this->paginateModelList('/api/sales/', ['sort' => 'TransactionDate-asc']);

        $events = [];
        foreach ($purchases as $row) {
            if (($row['IsPurchaseReturn'] ?? false) === true) {
                continue;
            }
            $at = $this->parseApiDate($row['TransactionDate'] ?? null);
            if ($since !== null && $at->lt($since)) {
                continue;
            }
            $events[] = ['type' => 'purchase', 'id' => (string) ($row['Id'] ?? ''), 'at' => $at];
        }
        foreach ($sales as $row) {
            if (($row['IsSalesReturn'] ?? false) === true) {
                continue;
            }
            $at = $this->parseApiDate($row['TransactionDate'] ?? null);
            if ($since !== null && $at->lt($since)) {
                continue;
            }
            $events[] = ['type' => 'sale', 'id' => (string) ($row['Id'] ?? ''), 'at' => $at];
        }

        usort($events, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        $log('Transaction events ordered: '.count($events).'.');

        if ($dryRun) {
            return;
        }

        foreach ($events as $event) {
            if ($event['id'] === '') {
                continue;
            }
            try {
                if ($event['type'] === 'purchase') {
                    $this->importPurchase($event['id'], $actorId, $log);
                } else {
                    $this->importSale($event['id'], $actorId, $log);
                }
            } catch (Throwable $e) {
                $log('Skip '.$event['type'].' '.$event['id'].': '.$e->getMessage());
            }
        }
    }

    private function parseApiDate(mixed $value): Carbon
    {
        if ($value === null || $value === '') {
            return Carbon::now();
        }

        return Carbon::parse((string) $value);
    }

    private function importPurchase(string $purchaseUuid, int $actorId, callable $log): void
    {
        if (GoodsReceipt::query()->where('dizicashier_uuid', $purchaseUuid)->exists()) {
            return;
        }

        $decoded = $this->client->getDecoded('/api/purchase/'.$purchaseUuid, []);
        $d = $decoded['Data'] ?? [];
        if (! is_array($d)) {
            return;
        }
        if (($d['IsPurchaseReturn'] ?? false) === true) {
            return;
        }

        $supplierUuid = (string) ($d['SupplierId'] ?? '');
        $supplier = Supplier::query()->where('dizicashier_uuid', $supplierUuid)->first();
        if (! $supplier) {
            throw new RuntimeException('Missing supplier '.$supplierUuid.' (run suppliers sync first).');
        }

        $lines = $d['PurchaseItems'] ?? [];
        if (! is_array($lines) || $lines === []) {
            return;
        }

        $at = $this->parseApiDate($d['Date'] ?? null);
        $wireHouseId = $d['WireHouseId'] ?? null;
        $location = $this->resolveLocationUuid(is_string($wireHouseId) ? $wireHouseId : null)
            ?? $this->resolveLocationUuid((string) ($lines[0]['WireHouseId'] ?? ''))
            ?? $this->fallbackLocation();

        DB::transaction(function () use ($d, $lines, $supplier, $location, $actorId, $at, $purchaseUuid, $log): void {
            $normalized = [];
            foreach ($lines as $pi) {
                if (! is_array($pi)) {
                    continue;
                }
                $itemUuid = (string) ($pi['ItemId'] ?? '');
                $product = Product::query()->where('dizicashier_uuid', $itemUuid)->first();
                if (! $product) {
                    continue;
                }
                $qty = max(1, (int) ($pi['Quantity'] ?? 1));
                $unit = $this->pickAmount(
                    $pi['DiscountedPricePerUnit'] ?? null,
                    $pi['PricePerUnit'] ?? null,
                    $pi['DiscountedPricePerUnitWithTax'] ?? null,
                    $pi['PricePerUnitWithTax'] ?? null,
                );
                $lineTotal = $this->pickAmount(
                    $pi['SubTotalAmount'] ?? null,
                    $pi['SubTotalAmountWithTax'] ?? null,
                    $unit * $qty,
                );
                $normalized[] = [
                    'product' => $product,
                    'qty' => $qty,
                    'unit' => $unit,
                    'tax' => (float) ($pi['TaxAmount'] ?? 0),
                    'line_total' => $lineTotal,
                    'diz' => $pi,
                ];
            }

            if ($normalized === []) {
                return;
            }

            $slip = trim((string) ($d['SlipNo'] ?? ''));
            $poNumber = $slip !== '' ? $slip : $this->documentNumbers->nextGlobalSequence(
                DocumentNumberGenerator::TYPE_DIZICASHIER_IMPORT_PO_FALLBACK,
                'DZ-P',
                6,
            );
            $poNumber = $this->uniquePoNumber($poNumber);

            $total = (float) ($d['TotalAmount'] ?? 0);
            $po = PurchaseOrder::query()->create([
                'dizicashier_uuid' => $purchaseUuid,
                'po_number' => $poNumber,
                'supplier_id' => $supplier->id,
                'destination_location_id' => $location->id,
                'status' => 'approved',
                'ordered_at' => $at->toDateString(),
                'expected_at' => null,
                'notes' => 'Imported from Dizicashier',
                'subtotal_amount' => (float) ($d['BasePriceAmount'] ?? $total),
                'discount_amount' => (float) ($d['DiscountAmount'] ?? 0),
                'tax_amount' => (float) ($d['TaxAmount'] ?? 0),
                'additional_cost_amount' => 0,
                'additional_costs' => null,
                'total_amount' => $total,
                'created_by' => $actorId,
                'approved_by' => $actorId,
                'approved_at' => $at,
            ]);

            foreach ($normalized as $row) {
                PurchaseOrderLine::query()->create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $row['product']->id,
                    'qty_ordered' => $row['qty'],
                    'qty_received' => 0,
                    'unit_cost' => $row['unit'],
                    'tax_amount' => $row['tax'],
                    'line_total' => $row['line_total'],
                ]);
            }

            $grnNumber = $this->uniqueGrnNumber($at);
            $grn = GoodsReceipt::query()->create([
                'dizicashier_uuid' => $purchaseUuid,
                'grn_number' => $grnNumber,
                'purchase_order_id' => $po->id,
                'supplier_id' => $supplier->id,
                'location_id' => $location->id,
                'status' => 'draft',
                'received_at' => $at->toDateString(),
                'delivery_note_ref' => trim((string) ($d['SupplierInvoiceNo'] ?? '')) ?: null,
                'created_by' => $actorId,
            ]);

            $po->refresh()->load('lines');
            foreach ($normalized as $row) {
                $product = $row['product'];
                $grnLine = $grn->lines()->create([
                    'product_id' => $product->id,
                    'qty_received' => $row['qty'],
                    'unit_cost' => $row['unit'],
                ]);
                if (! $product->is_serialized) {
                    continue;
                }
                $serialsFromApi = $row['diz']['ItemSerials'] ?? [];
                $serialsFromApi = is_array($serialsFromApi) ? $serialsFromApi : [];
                for ($i = 0; $i < $row['qty']; $i++) {
                    $ext = $this->serialNumberFromApiRow($serialsFromApi[$i] ?? null);
                    $serialNumber = $ext ?? $this->nextImportSerialNumber($at, 'purchase');
                    SerialItem::query()->create([
                        'product_id' => $product->id,
                        'serial_number' => $serialNumber,
                        'manufacturer_serial' => $ext,
                        'serial_source' => $ext ? 'manufacturer' : 'internal',
                        'status' => 'draft',
                        'goods_receipt_line_id' => $grnLine->id,
                    ]);
                }
            }

            $this->goodsReceiptPostingService->post($grn->fresh(['lines.serials', 'lines.product']), $actorId, $at);
            $log('Imported purchase '.$purchaseUuid.' as GRN '.$grnNumber);
        });
    }

    private function importSale(string $saleUuid, int $actorId, callable $log): void
    {
        if (SalesInvoice::query()->where('dizicashier_uuid', $saleUuid)->exists()) {
            return;
        }

        $decoded = $this->client->getDecoded('/api/sales/'.$saleUuid, []);
        $d = $decoded['Data'] ?? [];
        if (! is_array($d) || ($d['IsSalesReturn'] ?? false) === true) {
            return;
        }

        $customerUuid = (string) ($d['CustomerId'] ?? '');
        $customer = Customer::query()->where('dizicashier_uuid', $customerUuid)->first();
        if (! $customer) {
            throw new RuntimeException('Missing customer '.$customerUuid);
        }

        $salesItems = $d['SalesItems'] ?? [];
        if (! is_array($salesItems) || $salesItems === []) {
            return;
        }

        $location = $this->resolveLocationForSale($d, $salesItems);

        $at = $this->parseApiDate($d['Date'] ?? null);
        $total = (float) ($d['TotalAmount'] ?? 0);
        $received = (float) ($d['ReceivedAmount'] ?? 0);
        $installment = (bool) ($d['IsPayOnInstallment'] ?? false);
        $schedules = $d['SaleInstallments'] ?? [];
        if (! is_array($schedules)) {
            $schedules = [];
        }

        $due = (float) ($d['DueAmount'] ?? max(0, $total - $received));
        if ($installment && $schedules !== []) {
            // Dizicashier often sends DueAmount=0 on installment sales; use cash received vs total.
            $due = max(0, round($total - $received, 2));
        }

        $saleType = 'default';
        if ($installment && $schedules !== []) {
            $saleType = 'installment';
        } elseif ($due > 0.009) {
            $saleType = 'credit';
        }

        $status = $saleType === 'installment' ? 'active' : 'posted';
        $paymentStatus = $due <= 0.009 ? 'paid' : ($received > 0.009 ? 'partial' : 'unpaid');

        $slip = trim((string) ($d['SlipNo'] ?? ''));
        $invoiceNumber = $slip !== '' ? $slip : $this->documentNumbers->nextGlobalSequence(
            DocumentNumberGenerator::TYPE_DIZICASHIER_IMPORT_INVOICE_FALLBACK,
            'DZ-S',
            6,
        );
        $invoiceNumber = $this->uniqueInvoiceNumber($invoiceNumber);

        $receivable = (float) ($d['ReceivableForInstallment'] ?? $due);
        $downPayment = max(0, round($total - $receivable, 2));

        DB::transaction(function () use (
            $d,
            $salesItems,
            $customer,
            $location,
            $actorId,
            $at,
            $saleUuid,
            $total,
            $received,
            $due,
            $saleType,
            $status,
            $paymentStatus,
            $invoiceNumber,
            $installment,
            $schedules,
            $downPayment,
            $log,
        ): void {
            $invoice = SalesInvoice::query()->create([
                'dizicashier_uuid' => $saleUuid,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $at->toDateString(),
                'branch_id' => $location->id,
                'customer_id' => $customer->id,
                'sale_type' => $saleType,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'subtotal' => (float) ($d['BasePriceAmount'] ?? $total),
                'discount_total' => (float) ($d['DiscountAmount'] ?? 0),
                'tax_total' => (float) ($d['TaxAmount'] ?? 0),
                'grand_total' => $total,
                'amount_paid' => $received,
                'amount_due' => $due,
                'notes' => trim((string) ($d['Narration'] ?? '')) ?: null,
                'created_by' => $actorId,
                'posted_by' => $actorId,
                'posted_at' => $at,
                'credit_due_date' => $saleType === 'credit' ? $this->parseApiDate($d['ExpectedPaymentDate'] ?? $at)->toDateString() : null,
                'installment_down_payment' => $installment ? $downPayment : null,
                'installment_count' => $installment ? count($schedules) : null,
                'installment_first_due_date' => $installment && $schedules !== []
                    ? $this->parseApiDate($schedules[0]['DueDate'] ?? $at)->toDateString()
                    : null,
                'installment_frequency' => $installment ? 'monthly' : null,
                'installment_penalty_enabled' => false,
                'installment_penalty_rate' => null,
                'guarantor_name' => ($g = trim((string) ($d['GuarantorName'] ?? ''))) !== '' ? Str::limit($g, 150, '') : null,
                'guarantor_address' => ($ga = trim((string) ($d['GuarantorAddress'] ?? ''))) !== '' ? $ga : null,
                'guarantor_phone_numbers' => ($gp = trim((string) ($d['GuarantorContactNo'] ?? ''))) !== '' ? [$gp] : null,
                'guarantor_notes' => null,
            ]);

            foreach ($salesItems as $si) {
                if (! is_array($si)) {
                    continue;
                }
                $itemUuid = (string) ($si['ItemId'] ?? '');
                $product = Product::query()->where('dizicashier_uuid', $itemUuid)->first();
                if (! $product) {
                    continue;
                }
                $qty = max(1, (int) ($si['Quantity'] ?? 1));
                $unit = $this->pickAmount(
                    $si['DiscountedPricePerUnitWithTax'] ?? null,
                    $si['PricePerUnitWithTax'] ?? null,
                    $si['DiscountedPricePerUnit'] ?? null,
                    $si['PricePerUnit'] ?? null,
                );
                $lineTotal = $this->pickAmount(
                    $si['SubTotalAmountWithTax'] ?? null,
                    $si['SubTotalAmount'] ?? null,
                    $unit * $qty,
                );
                $warrantyMonths = (int) ($si['WarrantyPeriod'] ?? 12);
                if (($si['WarrantyPeriodType'] ?? '') !== 'Month' && ($si['WarrantyPeriodType'] ?? '') !== 'month') {
                    $warrantyMonths = 12;
                }

                $line = $invoice->lines()->create([
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'discount_amount' => (float) ($si['DiscountAmount'] ?? 0),
                    'tax_amount' => (float) ($si['TaxAmount'] ?? 0),
                    'line_total' => $lineTotal,
                    'warranty_months_snapshot' => $warrantyMonths,
                ]);

                if ($product->is_serialized) {
                    $serialsRaw = $si['SalesSerialItems'] ?? $si['ItemSerials'] ?? [];
                    $serialsRaw = is_array($serialsRaw) ? $serialsRaw : [];
                    for ($i = 0; $i < $qty; $i++) {
                        $ext = $this->serialNumberFromApiRow($serialsRaw[$i] ?? null);
                        $serial = null;
                        if ($ext !== null) {
                            $serial = SerialItem::query()
                                ->where('product_id', $product->id)
                                ->where('current_location_id', $location->id)
                                ->whereIn('status', ['in_stock', 'reserved'])
                                ->where(function ($q) use ($ext): void {
                                    $q->where('serial_number', $ext)->orWhere('manufacturer_serial', $ext);
                                })
                                ->first();
                        }
                        if (! $serial) {
                            $serialNumber = $ext ?? $this->nextImportSerialNumber($at, 'sale');
                            $serial = SerialItem::query()->create([
                                'product_id' => $product->id,
                                'serial_number' => $serialNumber,
                                'manufacturer_serial' => $ext,
                                'serial_source' => $ext ? 'manufacturer' : 'internal',
                                'current_location_id' => $location->id,
                                'status' => 'in_stock',
                                'goods_receipt_line_id' => null,
                                'purchase_cost' => (float) ($si['CostPrice'] ?? 0),
                            ]);
                        }
                        SaleLineSerial::query()->create([
                            'sales_invoice_line_id' => $line->id,
                            'serial_item_id' => $serial->id,
                        ]);
                        $this->salesPostingService->recordSerializedUnitSale($serial, $line, $invoice, $actorId, $at);
                    }
                } else {
                    StockLedger::query()->create([
                        'event_time' => $at,
                        'product_id' => $product->id,
                        'serial_item_id' => null,
                        'location_id' => $location->id,
                        'movement_type' => 'sale_issue',
                        'quantity_delta' => -1 * $qty,
                        'unit_cost' => null,
                        'reference_type' => 'SalesInvoice',
                        'reference_id' => $invoice->id,
                        'actor_user_id' => $actorId,
                    ]);
                }

                $this->inventoryBalanceService->recalculate($product->id, $location->id);
            }

            $this->salesPostingService->enforceSerializedSaleLedgerInvariant($invoice->fresh(['lines.saleLineSerials']));

            $invoice->refresh()->load('lines');
            if ($invoice->lines->isEmpty()) {
                $invoice->delete();

                return;
            }

            AccountsReceivableLedger::query()->create([
                'customer_id' => $customer->id,
                'event_time' => $at,
                'event_type' => 'invoice_posted',
                'amount_delta' => $total,
                'reference_type' => 'SalesInvoice',
                'reference_id' => $invoice->id,
                'actor_user_id' => $actorId,
            ]);
            Customer::query()->whereKey($customer->id)->increment('outstanding_balance', $total);

            $this->journalService->postSalesInvoiceGl($invoice->fresh(), $actorId);

            if ($installment && $schedules !== []) {
                $financed = round(array_sum(array_map(fn ($s) => (float) ($s['Amount'] ?? 0), $schedules)), 2);
                $schedulesPaid = round(array_sum(array_map(fn ($s) => (float) ($s['PaidAmount'] ?? 0), $schedules)), 2);
                $plan = InstallmentPlan::query()->create([
                    'sales_invoice_id' => $invoice->id,
                    'customer_id' => $customer->id,
                    'total_amount' => $total,
                    'down_payment' => $downPayment,
                    'financed_amount' => $financed,
                    'remaining_amount' => max(0, round($financed - $schedulesPaid, 2)),
                    'penalty_enabled' => false,
                    'penalty_rate' => 0.02,
                    'reschedule_count' => 0,
                    'status' => 'active',
                    'created_by' => $actorId,
                ]);
                $n = 1;
                foreach ($schedules as $sch) {
                    if (! is_array($sch)) {
                        continue;
                    }
                    $dueAmt = (float) ($sch['Amount'] ?? 0);
                    $paidAmt = max(0, round((float) ($sch['PaidAmount'] ?? 0), 2));
                    InstallmentSchedule::query()->create([
                        'installment_plan_id' => $plan->id,
                        'installment_no' => $n,
                        'due_date' => $this->parseApiDate($sch['DueDate'] ?? $at)->toDateString(),
                        'due_amount' => $dueAmt,
                        'paid_amount' => $paidAmt,
                        'late_fee_amount' => 0,
                        'status' => $this->installmentScheduleStatusFromDizicashier($dueAmt, $paidAmt),
                    ]);
                    $n++;
                }
            }

            if ($installment && $downPayment > 0.009) {
                app(InstallmentAdvanceService::class)->backfillMissingAdvancePayment(
                    $invoice->fresh(['installmentPlan.schedules']),
                    $actorId,
                );
            }

            $log('Imported sale '.$saleUuid.' as invoice '.$invoiceNumber);
        });
    }

    /**
     * Re-align imported invoice branch_id with Dizicashier CostCenter (outlet), not default item warehouse.
     *
     * @param  callable(string): void  $log
     */
    public function repairImportedInvoiceBranches(callable $log, bool $dryRun, ?int $invoiceId = null): void
    {
        $branchChangeService = app(SalesInvoiceBranchChangeService::class);
        $actorId = $this->resolveImportActorUserId();

        $query = SalesInvoice::query()
            ->whereNotNull('dizicashier_uuid')
            ->orderBy('id');

        if ($invoiceId !== null) {
            $query->whereKey($invoiceId);
        }

        $fixed = 0;
        $checked = 0;

        foreach ($query->cursor() as $invoice) {
            $checked++;
            $saleUuid = (string) $invoice->dizicashier_uuid;
            if ($saleUuid === '') {
                continue;
            }

            try {
                $decoded = $this->client->getDecoded('/api/sales/'.$saleUuid, []);
            } catch (Throwable $e) {
                $log('Skip invoice #'.$invoice->id.' ('.$invoice->invoice_number.'): '.$e->getMessage());

                continue;
            }

            $d = $decoded['Data'] ?? [];
            if (! is_array($d) || ($d['IsSalesReturn'] ?? false) === true) {
                continue;
            }

            $salesItems = $d['SalesItems'] ?? [];
            if (! is_array($salesItems)) {
                $salesItems = [];
            }

            $location = $this->resolveLocationForSale($d, $salesItems);
            if ((int) $invoice->branch_id === (int) $location->id) {
                continue;
            }

            $log(
                'Invoice #'.$invoice->id.' '.$invoice->invoice_number
                .': '.$invoice->branch?->name.' → '.$location->name
            );

            if (! $dryRun) {
                $branchChangeService->changeBranch($invoice, (int) $location->id, $actorId);
            }

            $fixed++;
        }

        $log('Branch repair checked '.$checked.' imported invoice(s); '.($dryRun ? 'would fix' : 'fixed').' '.$fixed.'.');
    }

    /**
     * Re-sync amount_paid / amount_due and installment schedule paid amounts from Dizicashier sale detail.
     *
     * @param  callable(string): void  $log
     */
    public function repairImportedSalePayments(
        callable $log,
        bool $dryRun,
        ?int $invoiceId = null,
        ?string $invoiceNumber = null,
    ): void {
        $installmentSync = app(InstallmentInvoiceSyncService::class);

        $query = SalesInvoice::query()
            ->whereNotNull('dizicashier_uuid')
            ->with(['installmentPlan.schedules'])
            ->orderBy('id');

        if ($invoiceId !== null) {
            $query->whereKey($invoiceId);
        }

        if ($invoiceNumber !== null && $invoiceNumber !== '') {
            $query->where('invoice_number', $invoiceNumber);
        }

        $checked = 0;
        $updated = 0;

        foreach ($query->cursor() as $invoice) {
            $checked++;
            $saleUuid = (string) $invoice->dizicashier_uuid;
            if ($saleUuid === '') {
                continue;
            }

            try {
                $decoded = $this->client->getDecoded('/api/sales/'.$saleUuid, []);
            } catch (Throwable $e) {
                $log('Skip invoice #'.$invoice->id.' ('.$invoice->invoice_number.'): '.$e->getMessage());

                continue;
            }

            $d = $decoded['Data'] ?? [];
            if (! is_array($d) || ($d['IsSalesReturn'] ?? false) === true) {
                continue;
            }

            $total = round((float) ($d['TotalAmount'] ?? $invoice->grand_total), 2);
            $received = round((float) ($d['ReceivedAmount'] ?? 0), 2);
            $installment = (bool) ($d['IsPayOnInstallment'] ?? false);
            $schedules = $d['SaleInstallments'] ?? [];
            if (! is_array($schedules)) {
                $schedules = [];
            }

            $due = (float) ($d['DueAmount'] ?? max(0, $total - $received));
            if ($installment && $schedules !== []) {
                $due = max(0, round($total - $received, 2));
            }

            $paymentStatus = $due <= 0.009 ? 'paid' : ($received > 0.009 ? 'partial' : 'unpaid');
            $receivable = (float) ($d['ReceivableForInstallment'] ?? $due);
            $downPayment = max(0, round($total - $receivable, 2));

            $before = sprintf(
                'paid %s / due %s (%s)',
                number_format((float) $invoice->amount_paid, 2),
                number_format((float) $invoice->amount_due, 2),
                $invoice->payment_status,
            );
            $after = sprintf(
                'paid %s / due %s (%s)',
                number_format($received, 2),
                number_format($due, 2),
                $paymentStatus,
            );

            if ($before !== $after
                || ($invoice->installmentPlan && $installment && $schedules !== [])) {
                $log('Invoice #'.$invoice->id.' '.$invoice->invoice_number.': '.$before.' → '.$after);
            } else {
                continue;
            }

            if ($dryRun) {
                $updated++;

                continue;
            }

            DB::transaction(function () use (
                $invoice,
                $received,
                $due,
                $paymentStatus,
                $downPayment,
                $installment,
                $schedules,
                $installmentSync,
            ): void {
                $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

                $invoice->update([
                    'amount_paid' => $received,
                    'amount_due' => $due,
                    'payment_status' => $paymentStatus,
                    'installment_down_payment' => $installment ? $downPayment : $invoice->installment_down_payment,
                ]);

                if ($installment && $schedules !== []) {
                    $plan = InstallmentPlan::query()
                        ->where('sales_invoice_id', $invoice->id)
                        ->lockForUpdate()
                        ->with(['schedules' => fn ($q) => $q->orderBy('installment_no')])
                        ->first();

                    if ($plan) {
                        $n = 1;
                        foreach ($schedules as $sch) {
                            if (! is_array($sch)) {
                                continue;
                            }

                            $schedule = $plan->schedules->firstWhere('installment_no', $n);
                            if ($schedule) {
                                $dueAmt = (float) ($sch['Amount'] ?? $schedule->due_amount);
                                $paidAmt = max(0, round((float) ($sch['PaidAmount'] ?? 0), 2));
                                $schedule->update([
                                    'due_amount' => $dueAmt,
                                    'paid_amount' => $paidAmt,
                                    'status' => $this->installmentScheduleStatusFromDizicashier($dueAmt, $paidAmt),
                                ]);
                            }
                            $n++;
                        }

                        $schedulesPaid = round((float) $plan->schedules()->sum('paid_amount'), 2);
                        $financed = round(array_sum(array_map(
                            fn ($s) => is_array($s) ? (float) ($s['Amount'] ?? 0) : 0,
                            $schedules,
                        )), 2);

                        $plan->update([
                            'down_payment' => $downPayment,
                            'financed_amount' => $financed > 0.009 ? $financed : $plan->financed_amount,
                            'remaining_amount' => max(0, round($financed - $schedulesPaid, 2)),
                        ]);

                        $installmentSync->refreshPlanTotals($plan->fresh(['schedules']));
                    }
                }
            });

            $updated++;
        }

        $log('Payment repair checked '.$checked.' imported invoice(s); '.($dryRun ? 'would update' : 'updated').' '.$updated.'.');
    }

    private function installmentScheduleStatusFromDizicashier(float $dueAmount, float $paidAmount): string
    {
        if ($paidAmount >= $dueAmount - 0.009) {
            return 'paid';
        }

        if ($paidAmount > 0.009) {
            return 'partially_paid';
        }

        return 'pending';
    }

    /**
     * Dizicashier sales store the selling outlet on CostCenterId; line WireHouseId is often the org default warehouse.
     *
     * @param  array<string, mixed>  $saleHeader
     * @param  list<mixed>  $salesItems
     */
    private function resolveLocationForSale(array $saleHeader, array $salesItems): Location
    {
        $costCenterId = (string) ($saleHeader['CostCenterId'] ?? '');
        if ($costCenterId !== '' && ! $this->isBlankDizicashierUuid($costCenterId)) {
            $warehouseUuid = $this->warehouseUuidForCostCenter($costCenterId);
            if ($warehouseUuid !== null) {
                $fromCostCenter = $this->resolveLocationUuid($warehouseUuid);
                if ($fromCostCenter) {
                    return $fromCostCenter;
                }
            }
        }

        foreach ($salesItems as $salesItem) {
            if (! is_array($salesItem)) {
                continue;
            }

            $wireHouseId = (string) ($salesItem['WireHouseId'] ?? '');
            if ($wireHouseId === '' || $this->isBlankDizicashierUuid($wireHouseId)) {
                continue;
            }

            $fromLine = $this->resolveLocationUuid($wireHouseId);
            if ($fromLine) {
                return $fromLine;
            }
        }

        return $this->fallbackLocation();
    }

    private function warehouseUuidForCostCenter(string $costCenterId): ?string
    {
        $this->ensureCostCenterWarehouseMap();

        $warehouseUuid = $this->costCenterWarehouseMap[$costCenterId] ?? null;

        return is_string($warehouseUuid) && $warehouseUuid !== '' ? $warehouseUuid : null;
    }

    private function ensureCostCenterWarehouseMap(): void
    {
        if ($this->costCenterWarehouseMap !== null) {
            return;
        }

        $this->costCenterWarehouseMap = [];

        try {
            $rows = $this->paginateModelList('/api/CostCenter', ['sort' => 'Name-asc']);
        } catch (Throwable) {
            return;
        }

        foreach ($rows as $row) {
            $costCenterId = (string) ($row['Id'] ?? '');
            if ($costCenterId === '') {
                continue;
            }

            $warehouses = $row['CostCenterWarehouses'] ?? [];
            if (! is_array($warehouses) || $warehouses === []) {
                continue;
            }

            $first = $warehouses[0];
            if (! is_array($first)) {
                continue;
            }

            $warehouseUuid = (string) ($first['WareHouseId'] ?? ($first['WareHouse']['Id'] ?? ''));
            if ($warehouseUuid === '' || $this->isBlankDizicashierUuid($warehouseUuid)) {
                continue;
            }

            $this->costCenterWarehouseMap[$costCenterId] = $warehouseUuid;
        }
    }

    private function resolveLocationUuid(?string $uuid): ?Location
    {
        if ($uuid === null || $uuid === '' || $uuid === '00000000-0000-0000-0000-000000000000') {
            return null;
        }

        return Location::query()->where('dizicashier_uuid', $uuid)->first();
    }

    private function uniquePoNumber(string $base): string
    {
        $candidate = Str::limit($base, 30, '');
        $i = 0;
        while (PurchaseOrder::query()->where('po_number', $candidate)->exists()) {
            $i++;
            $suffix = '-'.$i;
            $candidate = Str::limit(Str::beforeLast($base, '-') ?: $base, 30 - strlen($suffix), '').$suffix;
        }

        return $candidate;
    }

    private function uniqueGrnNumber(Carbon $at): string
    {
        for ($k = 0; $k < 50; $k++) {
            $candidate = $this->documentNumbers->next(
                DocumentNumberGenerator::TYPE_GOODS_RECEIPT_DIZI,
                'GRN-DZ',
                $at,
                'Ymd',
                5,
            );
            if (! GoodsReceipt::query()->where('grn_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not allocate unique GRN number.');
    }

    private function uniqueInvoiceNumber(string $base): string
    {
        $candidate = Str::limit($base, 30, '');
        $i = 0;
        while (SalesInvoice::query()->where('invoice_number', $candidate)->exists()) {
            $i++;
            $candidate = Str::limit($base.'-'.$i, 30, '');
        }

        return $candidate;
    }

    private function nextImportSerialNumber(Carbon $at, string $kind): string
    {
        $codeType = $kind === 'sale'
            ? DocumentNumberGenerator::TYPE_SERIAL_INTERNAL_SALE
            : DocumentNumberGenerator::TYPE_SERIAL_INTERNAL_PURCHASE;
        $prefix = $kind === 'sale' ? 'DZ-S' : 'DZ-P';

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $candidate = $this->documentNumbers->next($codeType, $prefix, $at, 'Ymd', 5);
            if (! SerialItem::query()->where('serial_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique serial number.');
    }

    private function serialNumberFromApiRow(mixed $row): ?string
    {
        if (! is_array($row)) {
            return null;
        }
        foreach (['SerialNumber', 'serialNumber', 'SerialNo', 'Imei', 'IMEI', 'Code'] as $key) {
            if (! empty($row[$key])) {
                return Str::limit(trim((string) $row[$key]), 100, '');
            }
        }

        return null;
    }

    private function pickAmount(mixed ...$candidates): float
    {
        $firstNumeric = null;
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            if (! is_numeric($candidate)) {
                continue;
            }
            $value = (float) $candidate;
            if ($firstNumeric === null) {
                $firstNumeric = $value;
            }
            if (abs($value) > 0.000001) {
                return $value;
            }
        }

        return $firstNumeric ?? 0.0;
    }
}
