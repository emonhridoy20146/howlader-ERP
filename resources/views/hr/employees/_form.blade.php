@php
    $departments = $departments ?? \App\Models\HrDepartment::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    $designations = \App\Models\HrDesignation::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    $locations = $locations ?? \App\Models\Location::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    $users = \App\Models\User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']);
    $selectedLocations = collect(old('location_ids', $employee->locations?->pluck('id')->all() ?? []))->map(fn ($id) => (int) $id)->all();
@endphp
@csrf
<div class="grid gap-4 md:grid-cols-2">
    <div>
        <label class="text-sm font-medium">First name</label>
        <input name="first_name" value="{{ old('first_name', $employee->first_name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
        @error('first_name')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="text-sm font-medium">Last name</label>
        <input name="last_name" value="{{ old('last_name', $employee->last_name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
    </div>
    <div>
        <label class="text-sm font-medium">Email</label>
        <input type="email" name="email" value="{{ old('email', $employee->email ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
    </div>
    <div>
        <label class="text-sm font-medium">Phone</label>
        <input name="phone" value="{{ old('phone', $employee->phone ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
    </div>
    <div class="md:col-span-2">
        <label class="text-sm font-medium">Branches</label>
        <p class="mt-1 text-xs text-slate-500">Select one or more branches where this employee works.</p>
        <div class="mt-2 grid gap-2 sm:grid-cols-2">
            @forelse ($locations as $loc)
                <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-slate-50/50 px-3 py-2 text-sm hover:border-slate-300 has-[:checked]:border-emerald-400 has-[:checked]:bg-emerald-50/40">
                    <input type="checkbox" name="location_ids[]" value="{{ $loc->id }}" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" @checked(in_array($loc->id, $selectedLocations, true))>
                    <span>{{ $loc->name }}</span>
                </label>
            @empty
                <p class="text-sm text-slate-500">No active branches available.</p>
            @endforelse
        </div>
        @error('location_ids')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
        @error('location_ids.*')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="text-sm font-medium">Linked user account</label>
        <select name="user_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            <option value="">None</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}" @selected(old('user_id', $employee->user_id ?? '') == $user->id)>{{ $user->name }} ({{ $user->email }})</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="text-sm font-medium">Department</label>
        <select name="hr_department_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            <option value="">None</option>
            @foreach ($departments as $dept)
                <option value="{{ $dept->id }}" @selected(old('hr_department_id', $employee->hr_department_id ?? '') == $dept->id)>{{ $dept->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="text-sm font-medium">Designation</label>
        <select name="hr_designation_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            <option value="">None</option>
            @foreach ($designations as $des)
                <option value="{{ $des->id }}" @selected(old('hr_designation_id', $employee->hr_designation_id ?? '') == $des->id)>{{ $des->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="text-sm font-medium">Gender</label>
        <select name="gender" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            <option value="">Prefer not to say</option>
            @foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $val => $label)
                <option value="{{ $val }}" @selected(old('gender', $employee->gender ?? '') === $val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="text-sm font-medium">Date of birth</label>
        <input type="date" name="date_of_birth" value="{{ old('date_of_birth', optional($employee->date_of_birth ?? null)->format('Y-m-d')) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
    </div>
    <div>
        <label class="text-sm font-medium">Joining date</label>
        <input type="date" name="joining_date" value="{{ old('joining_date', optional($employee->joining_date ?? null)->format('Y-m-d') ?? now()->format('Y-m-d')) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
    </div>
    <div>
        <label class="text-sm font-medium">Termination date</label>
        <input type="date" name="termination_date" value="{{ old('termination_date', optional($employee->termination_date ?? null)->format('Y-m-d')) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
    </div>
    <div>
        <label class="text-sm font-medium">Employment status</label>
        <select name="employment_status" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'terminated' => 'Terminated'] as $val => $label)
                <option value="{{ $val }}" @selected(old('employment_status', $employee->employment_status ?? 'active') === $val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="text-sm font-medium">Scheduled check-in</label>
        <input type="time" name="check_in_time" value="{{ old('check_in_time', $employee->formattedCheckInTime() ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        @error('check_in_time')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="text-sm font-medium">Scheduled check-out</label>
        <input type="time" name="check_out_time" value="{{ old('check_out_time', $employee->formattedCheckOutTime() ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        <p class="mt-1 text-xs text-slate-500">Used to prefill daily attendance.</p>
        @error('check_out_time')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="text-sm font-medium">Bank name</label>
        <input name="bank_name" value="{{ old('bank_name', $employee->bank_name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
    </div>
    <div>
        <label class="text-sm font-medium">Bank account</label>
        <input name="bank_account" value="{{ old('bank_account', $employee->bank_account ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
    </div>
    <div class="md:col-span-2">
        <label class="text-sm font-medium">Notes</label>
        <textarea name="notes" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('notes', $employee->notes ?? '') }}</textarea>
    </div>
    <div class="md:col-span-2">
        <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300" @checked(old('is_active', $employee->is_active ?? true))> Active employee record
        </label>
    </div>
</div>
<div class="mt-4"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Save</button></div>
