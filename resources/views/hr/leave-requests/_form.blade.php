@csrf
<div class="grid gap-4 md:grid-cols-2">
    <div>
        <label class="text-sm font-medium">Employee</label>
        <select name="employee_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
            <option value="">Select employee</option>
            @foreach ($employees as $emp)
                <option value="{{ $emp->id }}" @selected(old('employee_id', $leaveRequest->employee_id ?? '') == $emp->id)>{{ $emp->employee_code }} — {{ $emp->first_name }} {{ $emp->last_name }}</option>
            @endforeach
        </select>
        @error('employee_id')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="text-sm font-medium">Leave type</label>
        <select name="leave_type_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
            <option value="">Select type</option>
            @foreach ($leaveTypes as $type)
                <option value="{{ $type->id }}" @selected(old('leave_type_id', $leaveRequest->leave_type_id ?? '') == $type->id)>{{ $type->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="text-sm font-medium">Start date</label>
        <input type="date" name="start_date" value="{{ old('start_date', optional($leaveRequest->start_date ?? null)->format('Y-m-d')) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
    </div>
    <div>
        <label class="text-sm font-medium">End date</label>
        <input type="date" name="end_date" value="{{ old('end_date', optional($leaveRequest->end_date ?? null)->format('Y-m-d')) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
    </div>
    <div class="md:col-span-2">
        <label class="text-sm font-medium">Reason</label>
        <textarea name="reason" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('reason', $leaveRequest->reason ?? '') }}</textarea>
    </div>
</div>
<div class="mt-4"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Save</button></div>
