@csrf
<div class="grid gap-4 md:grid-cols-2" x-data="{
    basic: {{ old('basic_salary', $payScale->basic_salary ?? 0) }},
    house: {{ old('house_rent_allowance', $payScale->house_rent_allowance ?? 0) }},
    medical: {{ old('medical_allowance', $payScale->medical_allowance ?? 0) }},
    transport: {{ old('transport_allowance', $payScale->transport_allowance ?? 0) }},
    other: {{ old('other_allowance', $payScale->other_allowance ?? 0) }},
    get gross() { return Number(this.basic||0)+Number(this.house||0)+Number(this.medical||0)+Number(this.transport||0)+Number(this.other||0); }
}">
    <div class="md:col-span-2 grid gap-4 md:grid-cols-2">
        <div>
            <label class="text-sm font-medium">Name</label>
            <input name="name" value="{{ old('name', $payScale->name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
            @error('name')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="text-sm font-medium">Code</label>
            <input name="code" value="{{ old('code', $payScale->code ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </div>
    </div>
    <div>
        <label class="text-sm font-medium">Basic salary</label>
        <input type="number" step="0.01" min="0" name="basic_salary" x-model.number="basic" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
    </div>
    <div>
        <label class="text-sm font-medium">House rent allowance</label>
        <input type="number" step="0.01" min="0" name="house_rent_allowance" x-model.number="house" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
    </div>
    <div>
        <label class="text-sm font-medium">Medical allowance</label>
        <input type="number" step="0.01" min="0" name="medical_allowance" x-model.number="medical" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
    </div>
    <div>
        <label class="text-sm font-medium">Transport allowance</label>
        <input type="number" step="0.01" min="0" name="transport_allowance" x-model.number="transport" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
    </div>
    <div>
        <label class="text-sm font-medium">Other allowance</label>
        <input type="number" step="0.01" min="0" name="other_allowance" x-model.number="other" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
    </div>
    <div class="md:col-span-2 rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
        Gross salary: <span class="font-semibold tabular-nums" x-text="gross.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
    </div>
    <div class="md:col-span-2">
        <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300" @checked(old('is_active', $payScale->is_active ?? true))> Active
        </label>
    </div>
</div>
<div class="mt-4"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Save</button></div>
