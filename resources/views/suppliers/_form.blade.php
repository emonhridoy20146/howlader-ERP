@csrf
@php
    $supplierPhones = old('phone_numbers', isset($supplier) ? $supplier->normalizedPhoneNumbers() : []);
    if (!is_array($supplierPhones)) {
        $supplierPhones = [];
    }
    if (count($supplierPhones) === 0) {
        $supplierPhones = [''];
    }
@endphp
<div class="grid gap-4 md:grid-cols-2">
    <div><label class="text-sm font-medium">Name</label><input name="name" value="{{ old('name', $supplier->name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required></div>
    <div><label class="text-sm font-medium">Contact Person</label><input name="contact_person" value="{{ old('contact_person', $supplier->contact_person ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></div>
    <div class="md:col-span-2" x-data="{ phones: @js(array_values($supplierPhones)) }">
        <div class="flex items-center justify-between">
            <label class="text-sm font-medium">Phone Numbers</label>
            <button type="button" class="rounded-lg border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50" @click="phones.push('')">+ Add number</button>
        </div>
        <template x-for="(phone, i) in phones" :key="i">
            <div class="mt-2 flex gap-2">
                <input :name="`phone_numbers[${i}]`" x-model="phones[i]" class="w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="e.g. 017XXXXXXXX">
                <button
                    type="button"
                    class="rounded-lg border border-rose-300 px-3 py-2 text-xs text-rose-700 hover:bg-rose-50"
                    @click="phones.splice(i, 1); if (phones.length === 0) phones.push('')"
                >Remove</button>
            </div>
        </template>
        @error('phone_numbers')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        @error('phone_numbers.*')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div><label class="text-sm font-medium">Email</label><input name="email" value="{{ old('email', $supplier->email ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></div>
    <div><label class="text-sm font-medium">Tax Number</label><input name="tax_number" value="{{ old('tax_number', $supplier->tax_number ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></div>
    <div class="flex items-center gap-2 pt-6"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $supplier->is_active ?? true))><span class="text-sm">Active</span></div>
</div>
<div class="mt-4"><label class="text-sm font-medium">Address</label><textarea name="address" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('address', $supplier->address ?? '') }}</textarea></div>
<div class="mt-4"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Save</button></div>
