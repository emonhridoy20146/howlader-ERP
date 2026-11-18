@csrf
@php
    $managerUsers = $managerUsers ?? \App\Models\User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']);
    $managerOptions = $managerUsers->map(fn ($user) => [
        'value' => $user->id,
        'label' => trim($user->name.' · '.$user->email, ' ·'),
    ])->values()->all();
@endphp
<div class="grid gap-4 md:grid-cols-2">
    <div><label class="text-sm font-medium">Code</label><input name="code" value="{{ old('code', $location->code ?? '') }}" placeholder="Optional — generated from name if empty" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></div>
    <div><label class="text-sm font-medium">Name</label><input name="name" value="{{ old('name', $location->name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required></div>
    <div><label class="text-sm font-medium">Type</label><select name="type" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required><option value="warehouse" @selected(old('type', $location->type ?? '') === 'warehouse')>Warehouse</option><option value="branch" @selected(old('type', $location->type ?? '') === 'branch')>Branch</option></select></div>
    <div><label class="text-sm font-medium">Phone</label><input name="phone" value="{{ old('phone', $location->phone ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></div>
    <div>
        <label class="text-sm font-medium">Branch manager</label>
        <x-searchable-select
            name="manager_user_id"
            :options="$managerOptions"
            :selected="old('manager_user_id', $location->manager_user_id ?? '')"
            placeholder="No manager assigned"
        />
        <p class="mt-1 text-xs text-slate-500">This user approves stock transfers for this branch (sending or receiving).</p>
        @error('manager_user_id')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div><label class="text-sm font-medium">Manager name (optional)</label><input name="manager_name" value="{{ old('manager_name', $location->manager_name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Display name if different from user account"></div>
    <div class="flex items-center gap-2 pt-6"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $location->is_active ?? true))><span class="text-sm">Active</span></div>
</div>
<div class="mt-4"><label class="text-sm font-medium">Address</label><textarea name="address" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('address', $location->address ?? '') }}</textarea></div>
<p class="mt-4 text-xs text-slate-500">Payment methods are managed from the Payment Methods module and linked by location.</p>
<div class="mt-4"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Save</button></div>
