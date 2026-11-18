<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\CategoryTree;
use App\Support\CompanyPad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filtersFromRequest($request);

        $editId = $request->integer('edit');
        $editingCategory = $editId ? Category::find($editId) : null;
        $parents = Category::when($editingCategory, fn ($q) => $q->where('id', '!=', $editingCategory->id))
            ->orderBy('name')
            ->get();

        $categories = $this->filteredCategoriesQuery($filters)
            ->withCount('products')
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $allCategories = Category::query()
            ->withCount('products')
            ->orderBy('name')
            ->get();

        $kpis = [
            'total' => Category::count(),
            'root' => Category::whereNull('parent_id')->count(),
            'child' => Category::whereNotNull('parent_id')->count(),
            'filtered' => $categories->total(),
        ];

        return view('categories.index', compact('categories', 'editingCategory', 'parents', 'filters', 'kpis', 'allCategories'));
    }

    public function print(Request $request): View
    {
        $filters = $this->filtersFromRequest($request);
        $categories = $this->filteredCategoriesQuery($filters)
            ->withCount('products')
            ->get();

        $subtitleParts = ['Generated '.Carbon::now()->format('Y-m-d H:i')];
        if ($filters['q'] !== '') {
            $subtitleParts[] = 'Search: '.$filters['q'];
        }
        if ($filters['parent_id'] !== '') {
            $parent = Category::find((int) $filters['parent_id']);
            $subtitleParts[] = 'Parent: '.($parent?->name ?? $filters['parent_id']);
        }

        return view('categories.print', [
            'companyPad' => CompanyPad::settings(),
            'reportTitle' => 'Category list',
            'subtitle' => implode(' · ', $subtitleParts),
            'categories' => $categories,
            'kpis' => [
                'listed' => $categories->count(),
                'products' => (int) $categories->sum('products_count'),
            ],
        ]);
    }

    public function moveProducts(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_category_id' => ['required', 'integer', 'exists:categories,id'],
            'to_category_id' => ['required', 'integer', 'exists:categories,id', 'different:from_category_id'],
        ]);

        $fromCategory = Category::findOrFail($data['from_category_id']);
        $toCategory = Category::findOrFail($data['to_category_id']);

        $this->authorize('update', $fromCategory);
        $this->authorize('update', $toCategory);

        $moved = 0;
        DB::transaction(function () use ($data, &$moved): void {
            $moved = Product::query()
                ->where('category_id', $data['from_category_id'])
                ->update(['category_id' => $data['to_category_id']]);
        });

        return redirect()
            ->route('categories.index')
            ->with('status', "Moved {$moved} product(s) from «{$fromCategory->name}» to «{$toCategory->name}».");
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('categories.index', ['drawer' => 'create']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        Category::create($data);

        return redirect()->route('categories.index')->with('status', 'Category created successfully.');
    }

    public function edit(Category $category): RedirectResponse
    {
        return redirect()->route('categories.index', ['drawer' => 'edit', 'edit' => $category->id]);
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'exists:categories,id', Rule::notIn([$category->id])],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $category->update($data);

        return redirect()->route('categories.index')->with('status', 'Category updated successfully.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();

        return redirect()->route('categories.index')->with('status', 'Category deleted.');
    }

    public function bulkProducts(Request $request): View
    {
        $this->assertCanUpdateProductCategories();

        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'filter_category_id' => (string) $request->input('filter_category_id', ''),
            'target_category_id' => '',
        ];

        $categoryCollection = Category::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $treeNodes = CategoryTree::flatNodes($categoryCollection, includeDescendantProductCounts: true);
        $categorySelectOptions = CategoryTree::selectOptions($categoryCollection);
        $categoriesById = $categoryCollection->keyBy('id');

        $products = Product::query()
            ->with(['brand', 'category'])
            ->where('is_active', true)
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function (Builder $subQuery) use ($keyword): void {
                    $subQuery->where('sku', 'like', "%{$keyword}%")
                        ->orWhere('name', 'like', "%{$keyword}%")
                        ->orWhere('model_number', 'like', "%{$keyword}%")
                        ->orWhere('barcode', 'like', "%{$keyword}%");
                });
            })
            ->when($filters['filter_category_id'] !== '', function (Builder $query) use ($filters, $categoryCollection): void {
                $ids = CategoryTree::selfAndDescendantIds((int) $filters['filter_category_id'], $categoryCollection);
                $query->whereIn('category_id', $ids);
            })
            ->orderBy('name')
            ->paginate($this->resolvePerPage($request, 20))
            ->withQueryString();

        $brandSelectOptions = Brand::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $brand) => [
                'value' => (string) $brand->id,
                'label' => $brand->name,
                'search' => strtolower($brand->name),
            ])
            ->values()
            ->all();

        return view('categories.bulk-products', [
            'filters' => $filters,
            'treeNodes' => $treeNodes,
            'categorySelectOptions' => $categorySelectOptions,
            'brandSelectOptions' => $brandSelectOptions,
            'categoriesById' => $categoriesById,
            'products' => $products,
        ]);
    }

    public function patchBulkProduct(Request $request): JsonResponse
    {
        $this->assertCanUpdateProductCategories();

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'brand_id' => ['required', 'integer', 'exists:brands,id'],
        ]);

        $product = Product::query()->findOrFail($data['product_id']);
        $product->update([
            'category_id' => $data['category_id'],
            'brand_id' => $data['brand_id'],
        ]);

        $product->load(['brand', 'category']);
        $categories = Category::query()->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');

        return response()->json([
            'message' => 'Saved.',
            'product_id' => $product->id,
            'category_id' => (int) $product->category_id,
            'brand_id' => (int) $product->brand_id,
            'brand_name' => $product->brand?->name ?? '—',
            'category_label' => $product->category
                ? CategoryTree::breadcrumb($product->category, $categories)
                : '—',
        ]);
    }

    public function updateBulkProducts(Request $request): RedirectResponse|JsonResponse
    {
        $this->assertCanUpdateProductCategories();

        $filters = array_filter([
            'q' => trim((string) $request->input('q', '')),
            'filter_category_id' => (string) $request->input('filter_category_id', ''),
            'target_category_id' => (string) $request->input('target_category_id', ''),
            'page' => (string) $request->input('page', ''),
            'per_page' => (string) $request->input('per_page', ''),
        ], fn ($value) => $value !== '');

        $action = (string) $request->input('action', 'rows');

        if ($action === 'bulk') {
            $data = $request->validate([
                'product_ids' => ['required', 'array', 'min:1'],
                'product_ids.*' => ['integer', 'exists:products,id'],
                'target_category_id' => ['required', 'integer', 'exists:categories,id'],
            ]);

            $updated = Product::query()
                ->whereIn('id', $data['product_ids'])
                ->update(['category_id' => $data['target_category_id']]);

            $category = Category::query()->find($data['target_category_id']);
            $categories = Category::query()->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
            $categoryLabel = $category
                ? CategoryTree::breadcrumb($category, $categories)
                : '—';

            return $this->bulkProductsRespond($request, $filters, "Updated category for {$updated} selected product(s).", [
                'product_ids' => array_map('intval', $data['product_ids']),
                'category_label' => $categoryLabel,
            ]);
        }

        if ($action === 'bulk_brand') {
            $data = $request->validate([
                'product_ids' => ['required', 'array', 'min:1'],
                'product_ids.*' => ['integer', 'exists:products,id'],
                'target_brand_id' => ['required', 'integer', 'exists:brands,id'],
            ]);

            $updated = Product::query()
                ->whereIn('id', $data['product_ids'])
                ->update(['brand_id' => $data['target_brand_id']]);

            $brandName = Brand::query()->find($data['target_brand_id'])?->name ?? '—';

            return $this->bulkProductsRespond($request, $filters, "Updated brand for {$updated} selected product(s).", [
                'product_ids' => array_map('intval', $data['product_ids']),
                'brand_name' => $brandName,
            ]);
        }

        $data = $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*' => ['integer', 'exists:categories,id'],
            'brand_assignments' => ['required', 'array', 'min:1'],
            'brand_assignments.*' => ['integer', 'exists:brands,id'],
        ]);

        $updated = 0;
        DB::transaction(function () use ($data, &$updated): void {
            foreach ($data['assignments'] as $productId => $categoryId) {
                $productId = (int) $productId;
                $categoryId = (int) $categoryId;
                $brandId = (int) ($data['brand_assignments'][$productId] ?? 0);
                if ($brandId === 0) {
                    continue;
                }

                $updated += Product::query()
                    ->whereKey($productId)
                    ->update([
                        'category_id' => $categoryId,
                        'brand_id' => $brandId,
                    ]);
            }
        });

        return $this->bulkProductsRespond($request, $filters, "Saved category and brand changes for {$updated} product(s).");
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function bulkProductsRespond(Request $request, array $filters, string $message, array $extra = []): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(array_merge(['message' => $message], $extra));
        }

        return redirect()
            ->route('categories.bulk-products', $filters)
            ->with('status', $message);
    }

    private function assertCanUpdateProductCategories(): void
    {
        $user = auth()->user();
        if ($user === null) {
            abort(403);
        }

        if ($user->isAdmin() || $user->hasPermission('product.update')) {
            return;
        }

        abort(403);
    }

    /**
     * @return array{q: string, parent_id: string}
     */
    private function filtersFromRequest(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'parent_id' => (string) $request->input('parent_id', ''),
        ];
    }

    /**
     * @param  array{q: string, parent_id: string}  $filters
     * @return Builder<Category>
     */
    private function filteredCategoriesQuery(array $filters): Builder
    {
        return Category::query()
            ->with('parent', 'products')
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('name', 'like', "%{$keyword}%")
                        ->orWhereHas('parent', function ($parentQuery) use ($keyword): void {
                            $parentQuery->where('name', 'like', "%{$keyword}%");
                        });
                });
            })
            ->when($filters['parent_id'] !== '', fn ($query) => $query->where('parent_id', (int) $filters['parent_id']))
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
