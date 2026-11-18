@extends('layouts.app')

@section('title', 'Adjust stock')
@section('page-title', 'Adjust stock')

@section('content')
    <div class="mb-4">
        <a href="{{ route('inventory.stock-levels.index') }}" class="text-sm font-medium text-indigo-700 hover:underline">&larr; Back to stock by branch</a>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            <p class="font-semibold">Please fix the following:</p>
            <ul class="mt-2 list-disc pl-5">
                @foreach ($errors->all() as $msg)
                    <li>{{ $msg }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $onHand = (int) $balance['on_hand_qty'];
        $reservedQty = (int) $balance['reserved_qty'];
        $isSerialized = (bool) $product->is_serialized;
        $oldAdjustMode = old('adjust_mode', 'absolute');
        $oldQtyDelta = old('qty_delta');
        $initialUiMode = 'absolute';
        if ($oldAdjustMode === 'relative' && $oldQtyDelta !== null) {
            $initialUiMode = (int) $oldQtyDelta < 0 ? 'remove' : 'add';
        }
        $oldSerializedAdd = old('serialized_add_mode', 'auto');
    @endphp

    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm ring-1 ring-slate-100">
        <h2 class="text-lg font-semibold text-slate-900">{{ $product->name }}</h2>
        <p class="mt-1 text-sm text-slate-600">SKU {{ $product->sku }} · Branch {{ $locationName }}</p>
        @if ($onHand < 0)
            <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                On-hand is <span class="font-semibold">below zero</span> in the stock ledger (often from data fixes or over-issues). Use <span class="font-medium">Set exact quantity</span> or <span class="font-medium">Add to stock</span> to bring the count back in line.
                <span class="mt-1 block text-xs text-amber-800/95">While on-hand is negative, increases that only bring the balance up toward zero are posted as a ledger correction and do <span class="font-medium">not</span> create new serial records. If you raise on-hand above zero, only the extra units require new serials (auto or custom).</span>
            </p>
        @endif
        <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-3">
            <div><dt class="text-slate-500">On hand</dt><dd class="font-semibold tabular-nums text-slate-900">{{ number_format($onHand) }}</dd></div>
            <div><dt class="text-slate-500">Reserved</dt><dd class="font-semibold tabular-nums text-slate-900">{{ number_format($reservedQty) }}</dd></div>
            <div><dt class="text-slate-500">Available</dt><dd class="font-semibold tabular-nums text-slate-900">{{ number_format($balance['available_qty']) }}</dd></div>
        </dl>
    </div>

    <div
        class="max-w-xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm ring-1 ring-slate-100"
        x-data="{
            uiMode: @js($initialUiMode),
            onHand: {{ $onHand }},
            reserved: {{ $reservedQty }},
            relQty: {{ max(1, abs((int) old('qty_delta', 1))) }},
            absoluteTarget: {{ old('target_on_hand_qty', $onHand) }},
            isSerialized: {{ $isSerialized ? 'true' : 'false' }},
            serializedAddMode: @js($oldSerializedAdd === 'custom' ? 'custom' : 'auto'),
            resultingOnHand() {
                if (this.uiMode === 'add') return this.onHand + this.relQty;
                if (this.uiMode === 'remove') return this.onHand - this.relQty;
                return Number(this.absoluteTarget);
            },
            /** Net units added to on-hand (not used for serial-row creation when only correcting negative ledger). */
            newUnitsCount() {
                const r = this.resultingOnHand();
                const raw = r - this.onHand;
                if (raw <= 0) {
                    return 0;
                }
                if (this.onHand < 0) {
                    const hole = -this.onHand;

                    return Math.max(0, raw - Math.min(raw, hole));
                }

                return raw;
            },
            isIncrease() {
                if (!this.isSerialized) return false;
                return this.newUnitsCount() > 0;
            },
            isDecrease() {
                if (!this.isSerialized) return false;
                if (this.onHand < 0) {
                    return false;
                }
                // newUnitsCount() is clamped to >=0 — use removeUnitsCount for decreases (set exact qty or relative remove).
                return this.removeUnitsCount() > 0;
            },
            removeUnitsCount() {
                return Math.max(0, this.onHand - this.resultingOnHand());
            },
            setMode(mode) {
                this.uiMode = mode;
                if (mode === 'absolute') this.absoluteTarget = this.onHand;
            }
        }"
    >
        <p class="mb-5 text-sm text-slate-600">
            Use <span class="font-medium text-slate-800">add</span> or <span class="font-medium text-slate-800">remove</span> for quick changes, or <span class="font-medium text-slate-800">set exact quantity</span> for a full count.
            @if ($isSerialized)
                <span class="block mt-1">When adding serialized units you can auto-generate internal serial codes or paste your own (one per line).</span>
            @endif
        </p>

        <form method="post" action="{{ route('inventory.stock-levels.adjust.store') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="product_id" value="{{ $product->id }}">
            <input type="hidden" name="location_id" value="{{ $locationId }}">
            @if (!$isSerialized)
                <input type="hidden" name="serialized_add_mode" value="auto">
            @endif

            <fieldset>
                <legend class="sr-only">Adjustment style</legend>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        @click="setMode('add')"
                        :class="uiMode === 'add' ? 'border-indigo-600 bg-indigo-50 text-indigo-950' : 'border-slate-200 bg-white text-slate-800 hover:bg-slate-50'"
                        class="rounded-lg border px-3 py-2 text-sm font-medium shadow-sm transition"
                    >Add to stock</button>
                    <button
                        type="button"
                        @click="setMode('remove')"
                        :class="uiMode === 'remove' ? 'border-indigo-600 bg-indigo-50 text-indigo-950' : 'border-slate-200 bg-white text-slate-800 hover:bg-slate-50'"
                        class="rounded-lg border px-3 py-2 text-sm font-medium shadow-sm transition"
                    >Remove from stock</button>
                    <button
                        type="button"
                        @click="setMode('absolute')"
                        :class="uiMode === 'absolute' ? 'border-indigo-600 bg-indigo-50 text-indigo-950' : 'border-slate-200 bg-white text-slate-800 hover:bg-slate-50'"
                        class="rounded-lg border px-3 py-2 text-sm font-medium shadow-sm transition"
                    >Set exact quantity</button>
                </div>
                <input type="hidden" name="adjust_mode" :value="uiMode === 'absolute' ? 'absolute' : 'relative'">
                <input type="hidden" name="qty_delta" x-bind:disabled="uiMode === 'absolute'" :value="uiMode === 'add' ? relQty : (uiMode === 'remove' ? -relQty : 0)">
            </fieldset>

            <div x-show="uiMode === 'absolute'" class="space-y-1">
                <label for="target_on_hand_qty" class="block text-sm font-medium text-slate-700">New on-hand quantity</label>
                <input
                    type="number"
                    id="target_on_hand_qty"
                    name="target_on_hand_qty"
                    min="0"
                    required
                    x-model.number="absoluteTarget"
                    x-bind:disabled="uiMode !== 'absolute'"
                    class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 disabled:bg-slate-100 disabled:text-slate-500"
                >
            </div>

            <div x-show="uiMode !== 'absolute'" x-cloak class="space-y-1">
                <label for="rel_qty" class="block text-sm font-medium text-slate-700">How many units?</label>
                <input
                    type="number"
                    id="rel_qty"
                    min="1"
                    x-model.number="relQty"
                    class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                >
                <p class="text-sm text-slate-600">
                    <span class="font-medium text-slate-800">Resulting on-hand:</span>
                    <span class="tabular-nums font-semibold" x-text="resultingOnHand()"></span>
                    <span x-show="isSerialized && isDecrease() && uiMode !== 'absolute'" x-cloak class="block text-xs text-slate-500 mt-1">
                        Tick exactly <strong x-text="removeUnitsCount()"></strong> serial(s) below.
                    </span>
                </p>
            </div>

            @if ($isSerialized)
                <div class="rounded-lg border border-emerald-200 bg-emerald-50/70 p-4" x-show="isIncrease()" x-cloak>
                    <p class="text-sm font-medium text-emerald-950">New serial numbers</p>
                    <p class="mt-1 text-xs text-emerald-900/85">Adds <strong x-text="newUnitsCount()"></strong> new in-stock unit(s) at this branch.</p>
                    <div class="mt-3 flex flex-wrap gap-4">
                        <label class="inline-flex cursor-pointer items-center gap-2 text-sm">
                            <input type="radio" name="serialized_add_mode" value="auto" x-model="serializedAddMode" class="border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span>Auto-generate serials</span>
                        </label>
                        <label class="inline-flex cursor-pointer items-center gap-2 text-sm">
                            <input type="radio" name="serialized_add_mode" value="custom" x-model="serializedAddMode" class="border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span>Custom serials</span>
                        </label>
                    </div>
                    <div class="mt-3" x-show="serializedAddMode === 'custom'" x-cloak>
                        <label for="custom_serial_lines" class="block text-sm font-medium text-slate-700">
                            Paste serials <span class="font-normal text-slate-500">(exactly <span x-text="newUnitsCount()"></span> non-empty lines)</span>
                        </label>
                        <textarea
                            id="custom_serial_lines"
                            name="custom_serial_lines"
                            rows="5"
                            placeholder="One serial per line…"
                            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                        >{{ old('custom_serial_lines') }}</textarea>
                    </div>
                </div>

                <div class="rounded-lg border border-amber-200 bg-amber-50/80 p-4" x-show="isDecrease()" x-cloak>
                    <p class="text-sm font-medium text-amber-900">
                        Remove serial(s)
                        <span class="font-normal text-amber-800"> — select exactly <strong x-text="removeUnitsCount()"></strong> below</span>
                    </p>
                    <p class="mt-1 text-xs text-amber-800/90">Pick in-stock serials that physically left this branch (scrap, shrink, etc.). Reserved units are not listed.</p>
                    @if ($removableSerials->isEmpty())
                        <p class="mt-3 text-sm text-amber-900">No removable in-stock serials at this branch.</p>
                    @else
                        <ul class="mt-3 max-h-56 space-y-2 overflow-y-auto">
                            @foreach ($removableSerials as $serial)
                                <li class="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="serial_ids[]"
                                        value="{{ $serial->id }}"
                                        class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        @checked(in_array((string) $serial->id, (array) old('serial_ids', []), true))
                                    >
                                    <span class="font-mono">{{ $serial->serial_number }}</span>
                                    @if ($serial->manufacturer_serial)
                                        <span class="text-slate-500">· mfr {{ $serial->manufacturer_serial }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div>
                <label for="reason" class="block text-sm font-medium text-slate-700">Reason (optional)</label>
                <textarea id="reason" name="reason" rows="3" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">{{ old('reason') }}</textarea>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Save adjustment</button>
                <a href="{{ route('inventory.stock-levels.index') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 hover:bg-slate-50">Cancel</a>
            </div>
        </form>
    </div>
@endsection
