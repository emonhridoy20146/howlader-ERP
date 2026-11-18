<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BrandController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
        ];

        $editId = $request->integer('edit');
        $editingBrand = $editId ? Brand::find($editId) : null;
        $brands = Brand::query()
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('name', 'like', "%{$keyword}%")
                        ->orWhere('country_of_origin', 'like', "%{$keyword}%");
                });
            })
            ->withCount('products')
            ->with('products')
            ->orderBy('name')
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $kpis = [
            'total' => Brand::count(),
            'with_country' => Brand::whereNotNull('country_of_origin')->where('country_of_origin', '!=', '')->count(),
            'filtered' => $brands->total(),
        ];

        return view('brands.index', compact('brands', 'editingBrand', 'filters', 'kpis'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('brands.index', ['drawer' => 'create']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:brands,name'],
            'country_of_origin' => ['nullable', 'string', 'max:60'],
        ]);

        Brand::create($data);

        return redirect()->route('brands.index')->with('status', 'Brand created successfully.');
    }

    public function edit(Brand $brand): RedirectResponse
    {
        return redirect()->route('brands.index', ['drawer' => 'edit', 'edit' => $brand->id]);
    }

    public function update(Request $request, Brand $brand): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:brands,name,'.$brand->id],
            'country_of_origin' => ['nullable', 'string', 'max:60'],
        ]);

        $brand->update($data);

        return redirect()->route('brands.index')->with('status', 'Brand updated successfully.');
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        $brand->delete();

        return redirect()->route('brands.index')->with('status', 'Brand deleted.');
    }
}
