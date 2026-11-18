@csrf
<div class="grid gap-4 md:grid-cols-2">
    <x-model-photo-upload :existing-url="$customer->exists ? $customer->photoUrl() : null" :label="__('Photo')" />
    <div><label class="text-sm font-medium">Customer Code</label><input name="customer_code" value="{{ old('customer_code', $customer->customer_code ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required></div>
    <div><label class="text-sm font-medium">Name</label><input name="name" value="{{ old('name', $customer->name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required></div>
    <div><label class="text-sm font-medium">Primary Phone</label><input name="phone" value="{{ old('phone', $customer->phone ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required></div>
    <div><label class="text-sm font-medium">Email</label><input name="email" value="{{ old('email', $customer->email ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></div>
    @php
        $branchList = $branchesForForm ?? collect();
        $branchFormOptions = $branchList->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->values()->all();
        $selectedBranchId = old('branch_id', $customer->branch_id ?? '');
    @endphp
    <div class="md:col-span-2">
        <label class="text-sm font-medium">Business branch</label>
        <p class="mt-0.5 text-xs text-slate-500">Location where this customer primarily does business.</p>
        <div class="mt-1">
            <x-searchable-select name="branch_id" :options="$branchFormOptions" :selected="$selectedBranchId !== null && $selectedBranchId !== '' ? (string) $selectedBranchId : ''" placeholder="No branch selected" />
        </div>
    </div>
    <div><label class="text-sm font-medium">ID Type</label><input name="id_type" value="{{ old('id_type', $customer->id_type ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></div>
    <div><label class="text-sm font-medium">ID Number</label><input name="id_number" value="{{ old('id_number', $customer->id_number ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></div>
    <div class="flex items-center gap-2 pt-6"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $customer->is_active ?? true))><span class="text-sm">Active</span></div>
</div>
<div class="mt-4">
    <label class="text-sm font-medium">Additional Phones</label>
    @php
        $oldPhoneNumbers = old('phone_numbers');
        $additionalPhones = is_array($oldPhoneNumbers)
            ? $oldPhoneNumbers
            : ($customer->phone_numbers ?? []);
        $additionalPhones = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $additionalPhones
        ), static fn (string $value): bool => $value !== ''));
        if ($additionalPhones === []) {
            $additionalPhones = [''];
        }
    @endphp
    <div x-data="{ phones: @js($additionalPhones) }" class="mt-1 space-y-2">
        <template x-for="(phoneValue, index) in phones" :key="index">
            <div class="flex items-center gap-2">
                <input type="text" :name="'phone_numbers[' + index + ']'" x-model="phones[index]" class="w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Additional phone">
                <button
                    type="button"
                    class="rounded border border-rose-200 bg-rose-50 px-2 py-2 text-xs font-medium text-rose-700 hover:bg-rose-100"
                    @click="phones.splice(index, 1); if (phones.length === 0) { phones.push(''); }"
                    :disabled="phones.length === 1"
                >-</button>
            </div>
        </template>
        <button type="button" class="rounded border border-slate-300 px-3 py-1.5 text-xs font-medium hover:bg-slate-50" @click="phones.push('')">+ Add Phone</button>
    </div>
</div>
<div class="mt-4"><label class="text-sm font-medium">Address</label><textarea name="address" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('address', $customer->address ?? '') }}</textarea></div>

@php
    $guarantorList = ($guarantorCandidates ?? collect())
        ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name.' ('.$c->customer_code.($c->phone ? ' · '.$c->phone : '').')'])
        ->values()
        ->all();
    $selectedGuarantorId = old('default_guarantor_id', $customer->default_guarantor_id ?? '');
    $guarantorMode = old('guarantor_mode', ($selectedGuarantorId !== '' && $selectedGuarantorId !== null) ? 'existing' : (old('new_guarantor_name') ? 'new' : 'none'));
@endphp
<div class="mt-6 border-t border-slate-200 pt-4 space-y-4">
    <div>
        <h3 class="text-sm font-semibold text-slate-800 uppercase tracking-wider">Default guarantor</h3>
        <p class="mt-1 text-xs text-slate-500">Optional. Used on installment invoices for this customer and can be attached in one click.</p>
    </div>
    <div x-data="{ mode: @js($guarantorMode) }" class="space-y-4">
        <div class="flex flex-wrap gap-2">
            <label class="inline-flex cursor-pointer items-center rounded-lg border px-3 py-2 text-xs font-medium" :class="mode === 'none' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 text-slate-700'">
                <input type="radio" name="guarantor_mode" value="none" x-model="mode" class="sr-only"> None
            </label>
            <label class="inline-flex cursor-pointer items-center rounded-lg border px-3 py-2 text-xs font-medium" :class="mode === 'existing' ? 'border-emerald-600 bg-emerald-50 text-emerald-900' : 'border-slate-300 text-slate-700'">
                <input type="radio" name="guarantor_mode" value="existing" x-model="mode" class="sr-only"> Select existing
            </label>
            <label class="inline-flex cursor-pointer items-center rounded-lg border px-3 py-2 text-xs font-medium" :class="mode === 'new' ? 'border-indigo-600 bg-indigo-50 text-indigo-900' : 'border-slate-300 text-slate-700'">
                <input type="radio" name="guarantor_mode" value="new" x-model="mode" class="sr-only"> Create new guarantor
            </label>
        </div>
        <div x-show="mode === 'existing'" x-cloak>
            <label class="text-sm font-medium text-slate-700">Guarantor customer</label>
            <div class="mt-1">
                <x-searchable-select name="default_guarantor_id" :options="$guarantorList" :selected="$selectedGuarantorId" placeholder="Search by name, code, or phone…" />
            </div>
            @error('default_guarantor_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div x-show="mode === 'new'" x-cloak class="grid gap-4 md:grid-cols-2">
            <div><label class="text-sm font-medium">Guarantor name</label><input name="new_guarantor_name" value="{{ old('new_guarantor_name') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></div>
            <div><label class="text-sm font-medium">Guarantor phone</label><input name="new_guarantor_phone" value="{{ old('new_guarantor_phone') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></div>
            <div><label class="text-sm font-medium">Guarantor email</label><input name="new_guarantor_email" value="{{ old('new_guarantor_email') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></div>
            <div class="md:col-span-2"><label class="text-sm font-medium">Guarantor address</label><textarea name="new_guarantor_address" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('new_guarantor_address') }}</textarea></div>
            @error('new_guarantor_name')<p class="md:col-span-2 text-xs text-rose-600">{{ $message }}</p>@enderror
            @error('new_guarantor_phone')<p class="md:col-span-2 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
    </div>
</div>

<div class="mt-6 border-t border-slate-200 pt-4 space-y-4">
    <h3 class="text-sm font-semibold text-slate-800 uppercase tracking-wider">Verification Documents</h3>

    <!-- Customer NID File Upload -->
    <div>
        <label class="text-xs font-medium text-slate-700 block">Customer NID File</label>
        @if ($customer->exists && $customer->nid_path)
            <div class="mt-1 flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs">
                <a href="{{ $customer->nidUrl() }}" target="_blank" class="font-mono text-indigo-600 hover:underline truncate max-w-[200px]" title="{{ $customer->nid_path }}">
                    {{ basename($customer->nid_path) }}
                </a>
                <label class="inline-flex items-center gap-1 text-rose-600 cursor-pointer select-none">
                    <input type="checkbox" name="remove_nid" value="1" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                    Remove
                </label>
            </div>
        @endif
        <input type="file" name="nid_file" class="mt-1 block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-slate-50 file:text-slate-700 hover:file:bg-slate-100">
    </div>

    <!-- General Attachments Upload -->
    <div>
        <label class="text-xs font-medium text-slate-700 block">Verification Attachments</label>
        @if ($customer->exists && $customer->attachments && count($customer->attachments) > 0)
            <div class="mt-1 space-y-1.5">
                @foreach ($customer->attachments as $path)
                    <div class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs">
                        <a href="{{ Storage::disk('public')->url($path) }}" target="_blank" class="font-mono text-indigo-600 hover:underline truncate max-w-[200px]" title="{{ $path }}">
                            {{ basename($path) }}
                        </a>
                        <label class="inline-flex items-center gap-1 text-rose-600 cursor-pointer select-none">
                            <input type="checkbox" name="remove_attachments[]" value="{{ $path }}" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                            Remove
                        </label>
                    </div>
                @endforeach
            </div>
        @endif
        <input type="file" name="new_attachments[]" multiple class="mt-2 block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-slate-50 file:text-slate-700 hover:file:bg-slate-100">
        <p class="text-[10px] text-slate-400 mt-1">You can select multiple files at once.</p>
    </div>
</div>

<div class="mt-4"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Save</button></div>
