@csrf
<div class="grid gap-4 md:grid-cols-2">
    <div>
        <label class="text-sm font-medium">Name</label>
        <input name="name" value="{{ old('name', $department->name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
        @error('name')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="text-sm font-medium">Code</label>
        <input name="code" value="{{ old('code', $department->code ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        @error('code')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div class="md:col-span-2">
        <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300" @checked(old('is_active', $department->is_active ?? true))>
            Active
        </label>
    </div>
</div>
<div class="mt-4">
    <button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Save</button>
</div>
