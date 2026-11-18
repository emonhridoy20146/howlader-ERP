{{-- Shared fields; parent supplies @csrf, submit row, and $method / $locations / $types --}}
@php
    $initialType = old('type', $method->type ?? 'cash');
    if (! in_array($initialType, array_keys($types), true)) {
        $initialType = 'cash';
    }
@endphp
<div class="space-y-8" x-data="{ paymentType: @js($initialType) }">
    <section class="rounded-xl border border-slate-200 bg-slate-50/40 p-4 sm:p-5">
        <h2 class="text-sm font-semibold text-slate-900">Identity</h2>
        <p class="mt-1 text-xs text-slate-500">Shown when recording payments. Code must stay unique.</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label for="pm-code" class="text-sm font-medium text-slate-700">Code</label>
                <input id="pm-code" name="code" value="{{ old('code', $method->code ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 font-mono text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" required maxlength="40">
            </div>
            <div class="sm:col-span-2">
                <label for="pm-name" class="text-sm font-medium text-slate-700">Display name</label>
                <input id="pm-name" name="name" value="{{ old('name', $method->name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" required maxlength="120">
            </div>
            <div>
                <label for="pm-type" class="text-sm font-medium text-slate-700">Type</label>
                <select id="pm-type" name="type" x-model="paymentType" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" required>
                    @foreach($types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="pm-location" class="text-sm font-medium text-slate-700">Branch scope</label>
                <select id="pm-location" name="location_id" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200">
                    <option value="">Global — available at every branch</option>
                    @foreach($locations as $location)
                        <option value="{{ $location->id }}" @selected((string) old('location_id', $method->location_id ?? '') === (string) $location->id)>{{ $location->name }} only</option>
                    @endforeach
                </select>
                <p class="mt-1.5 text-xs text-slate-500">Global methods need a <strong>wallet map per branch</strong> under Payment → wallet.</p>
            </div>
        </div>
    </section>

    <fieldset
        class="min-w-0 rounded-xl border border-slate-200 p-4 sm:p-5"
        x-show="paymentType === 'cash'"
        x-cloak
        :disabled="paymentType !== 'cash'"
    >
        <legend class="text-sm font-semibold text-slate-900">Cash</legend>
        <p class="mt-3 text-xs leading-relaxed text-slate-600">No bank account or terminal fields are required. Use <strong>Payment → wallet</strong> to link this method to a ledger wallet per branch.</p>
    </fieldset>

    <fieldset
        class="min-w-0 rounded-xl border border-slate-200 p-4 sm:p-5"
        x-show="paymentType === 'bank'"
        x-cloak
        :disabled="paymentType !== 'bank'"
    >
        <legend class="text-sm font-semibold text-slate-900">Bank account</legend>
        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <label class="text-sm font-medium text-slate-700">Account name</label>
                <input name="account_name" value="{{ old('account_name', $method->account_name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" maxlength="150">
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700">Account number</label>
                <input name="account_number" value="{{ old('account_number', $method->account_number ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" maxlength="80">
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700">Bank name</label>
                <input name="bank_name" value="{{ old('bank_name', $method->bank_name ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" maxlength="120">
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700">Bank branch</label>
                <input name="bank_branch" value="{{ old('bank_branch', $method->bank_branch ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" maxlength="120">
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700">Routing number</label>
                <input name="routing_number" value="{{ old('routing_number', $method->routing_number ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" maxlength="60">
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700">SWIFT</label>
                <input name="swift_code" value="{{ old('swift_code', $method->swift_code ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" maxlength="40">
            </div>
        </div>
    </fieldset>

    <fieldset
        class="min-w-0 rounded-xl border border-slate-200 p-4 sm:p-5"
        x-show="paymentType === 'card_terminal'"
        x-cloak
        :disabled="paymentType !== 'card_terminal'"
    >
        <legend class="text-sm font-semibold text-slate-900">Card terminal</legend>
        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label class="text-sm font-medium text-slate-700">Provider</label>
                <input name="terminal_provider" value="{{ old('terminal_provider', $method->terminal_provider ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" maxlength="120">
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700">Terminal ID</label>
                <input name="terminal_id" value="{{ old('terminal_id', $method->terminal_id ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" maxlength="80">
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700">Merchant ID</label>
                <input name="merchant_id" value="{{ old('merchant_id', $method->merchant_id ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" maxlength="80">
            </div>
        </div>
    </fieldset>

    <fieldset
        class="min-w-0 rounded-xl border border-slate-200 p-4 sm:p-5"
        x-show="paymentType === 'mobile_banking'"
        x-cloak
        :disabled="paymentType !== 'mobile_banking'"
    >
        <legend class="text-sm font-semibold text-slate-900">Mobile banking</legend>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <div>
                <label class="text-sm font-medium text-slate-700">Provider</label>
                <input name="mobile_provider" value="{{ old('mobile_provider', $method->mobile_provider ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" maxlength="120">
            </div>
            <div>
                <label class="text-sm font-medium text-slate-700">Mobile number</label>
                <input name="mobile_number" value="{{ old('mobile_number', $method->mobile_number ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" maxlength="30">
            </div>
        </div>
    </fieldset>

    <section class="rounded-xl border border-slate-200 p-4 sm:p-5">
        <h2 class="text-sm font-semibold text-slate-900">Status & notes</h2>
        <div class="mt-4 flex flex-wrap items-center gap-3">
            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $method->is_active ?? true)) class="rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                <span class="text-sm font-medium text-slate-800">Active</span>
            </label>
        </div>
        <div class="mt-4">
            <label class="text-sm font-medium text-slate-700">Internal notes</label>
            <textarea name="notes" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200">{{ old('notes', $method->notes ?? '') }}</textarea>
        </div>
    </section>
</div>
