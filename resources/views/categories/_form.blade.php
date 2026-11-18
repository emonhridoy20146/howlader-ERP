@csrf
<div class="grid gap-4 md:grid-cols-3">
    <div>
        <label class="text-sm font-medium">Name</label>
        <input name="name" value="{{ old('name', $category->name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
        @error('name')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="text-sm font-medium">Parent</label>
        @php
            $parentOptions = $parents->map(fn ($parent) => [
                'value' => (string) $parent->id,
                'label' => $parent->name,
            ])->values()->all();
        @endphp
        <div class="mt-1">
            <x-searchable-select
                name="parent_id"
                :options="$parentOptions"
                :selected="(string) old('parent_id', $category->parent_id ?? '')"
                placeholder="None (root category)"
            />
        </div>
        @error('parent_id')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="text-sm font-medium">Sort Order</label>
        <input type="number" name="sort_order" value="{{ old('sort_order', $category->sort_order ?? 0) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" min="0" required>
        @error('sort_order')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>
</div>
<div class="mt-4">
    <button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Save</button>
</div>
