<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Product;
use App\Support\LedgerBackedInventory;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    /**
     * Public product catalog: browse, filter by category, filter by stock
     * (in-stock only by default). No cart/checkout — buyers reach out to the
     * vendor directly per product.
     */
    public function index(Request $request): View
    {
        $q = trim((string) $request->input('q', ''));
        $categoryId = $request->integer('category_id') ?: null;
        $stock = (string) $request->input('stock', 'in_stock');
        if (! in_array($stock, ['in_stock', 'all'], true)) {
            $stock = 'in_stock';
        }

        $activeProductsCount = fn ($query) => $query->where('is_active', true);

        $categories = Category::query()
            ->whereHas('products', $activeProductsCount)
            ->withCount(['products' => $activeProductsCount])
            ->orderBy('name')
            ->get(['id', 'name']);

        $topCategories = $categories->sortByDesc('products_count')->take(10)->values();

        $products = Product::query()
            ->with('category')
            ->where('is_active', true)
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->when($q !== '', function ($query) use ($q): void {
                $query->where(function ($sub) use ($q): void {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('sku', 'like', "%{$q}%")
                        ->orWhere('model_number', 'like', "%{$q}%");
                });
            })
            ->when($stock === 'in_stock', function ($query): void {
                $query->whereExists(function ($sub): void {
                    $sub->fromSub($this->stockSummary(), 'agg')
                        ->selectRaw('1')
                        ->whereColumn('agg.product_id', 'products.id')
                        ->where('agg.available_qty', '>', 0);
                });
            })
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString();

        $productIds = $products->getCollection()->pluck('id');
        $availabilityByProduct = $productIds->isNotEmpty()
            ? $this->stockSummary()->whereIn('ib.product_id', $productIds->all())->pluck('available_qty', 'product_id')
            : collect();

        $stats = [
            'products' => Product::query()->where('is_active', true)->count(),
            'categories' => $categories->count(),
            'in_stock' => DB::query()
                ->fromSub($this->stockSummary(), 'agg')
                ->where('agg.available_qty', '>', 0)
                ->count(),
        ];

        $activeCategoryName = $categoryId !== null
            ? $categories->firstWhere('id', $categoryId)?->name
            : null;

        return view('storefront.index', [
            'products' => $products,
            'categories' => $categories,
            'topCategories' => $topCategories,
            'availabilityByProduct' => $availabilityByProduct,
            'vendor' => $this->vendorInfo(),
            'stats' => $stats,
            'filters' => [
                'q' => $q,
                'category_id' => $categoryId,
                'stock' => $stock,
            ],
            'activeCategoryName' => $activeCategoryName,
        ]);
    }

    public function show(Product $product): View
    {
        abort_unless($product->is_active, 404);

        $product->load(['category']);

        $available = (float) ($this->stockSummary()
            ->where('ib.product_id', $product->id)
            ->value('available_qty') ?? 0);

        $related = Product::query()
            ->where('is_active', true)
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->orderBy('name')
            ->limit(6)
            ->get();

        $relatedIds = $related->pluck('id');
        $availabilityByProduct = $relatedIds->isNotEmpty()
            ? $this->stockSummary()->whereIn('ib.product_id', $relatedIds->all())->pluck('available_qty', 'product_id')
            : collect();

        return view('storefront.show', [
            'product' => $product,
            'available' => $available,
            'inStock' => $available > 0,
            'related' => $related,
            'availabilityByProduct' => $availabilityByProduct,
            'vendor' => $this->vendorInfo(),
        ]);
    }

    private function stockSummary(): Builder
    {
        return DB::query()
            ->fromSub(LedgerBackedInventory::balancesSubquery(), 'ib')
            ->selectRaw('ib.product_id, SUM(ib.available_qty) as available_qty')
            ->groupBy('ib.product_id');
    }

    /**
     * @return array{name: string, logo_path: string|null, phone: string|null, email: string|null, website: string|null, address: string|null}
     */
    private function vendorInfo(): array
    {
        $settings = AppSetting::getMany([
            'company_pad_name',
            'company_pad_logo_path',
            'company_pad_phone',
            'company_pad_email',
            'company_pad_website',
            'company_pad_address',
        ]);

        return [
            'name' => $settings['company_pad_name'] ?: config('app.name'),
            'logo_path' => $settings['company_pad_logo_path'] ?: null,
            'phone' => $settings['company_pad_phone'] ?: null,
            'email' => $settings['company_pad_email'] ?: null,
            'website' => $settings['company_pad_website'] ?: null,
            'address' => $settings['company_pad_address'] ?: null,
        ];
    }
}
