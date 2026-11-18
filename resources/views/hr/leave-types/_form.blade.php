@csrf
<div class="grid gap-4 md:grid-cols-2">
    <div>
        <label class="text-sm font-medium">Name</label>
        <input name="name" value="{{ old('name', $leaveType->name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
        @error('name')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="text-sm font-medium">Max days per year</label>
        <input type="number" min="0" max="366" name="max_days_per_year" value="{{ old('max_days_per_year', $leaveType->max_days_per_year ?? 0) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
    </div>
    <div class="md:col-span-2 flex flex-wrap gap-4">
        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="is_paid" value="1" class="rounded border-slate-300" @checked(old('is_paid', $leaveType->is_paid ?? true))> Paid leave</label>
        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="requires_approval" value="1" class="rounded border-slate-300" @checked(old('requires_approval', $leaveType->requires_approval ?? true))> Requires approval</label>
        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" class="rounded border-slate-300" @checked(old('is_active', $leaveType->is_active ?? true))> Active</label>
    </div>
</div>
<div class="mt-4"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Save</button></div>
