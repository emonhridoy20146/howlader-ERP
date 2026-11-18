@csrf
<div class="grid gap-4 md:grid-cols-2">
    <div>
        <label class="text-sm font-medium">Name</label>
        <input name="name" value="{{ old('name', $brand->name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
        @error('name')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="text-sm font-medium">Country of Origin</label>
        <input name="country_of_origin" value="{{ old('country_of_origin', $brand->country_of_origin ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        @error('country_of_origin')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>
</div>
<div class="mt-4">
    <button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Save</button>
</div>
