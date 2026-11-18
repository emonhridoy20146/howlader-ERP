@php
    $monthly = $monthlyTargetProgress;
    $canManageMonthly = auth()->user()?->can('create', \App\Models\BranchMonthlyTarget::class);
@endphp

<div class="mt-10 border-t border-slate-200 pt-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h3 class="text-base font-semibold text-slate-900">Monthly targets</h3>
            <p class="mt-1 text-sm text-slate-600">Sales and collection targets for each month of {{ $monthly['year'] }}.</p>
        </div>
        <form method="get" action="{{ route('locations.show', $location) }}" class="flex flex-wrap items-end gap-2">
            <input type="hidden" name="tab" value="targets">
            <label class="text-xs font-medium text-slate-600">
                Year
                <select name="target_year" class="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm" onchange="this.form.submit()">
                    @for ($y = (int) now()->year + 1; $y >= (int) now()->year - 5; $y--)
                        <option value="{{ $y }}" @selected($targetYear === $y)>{{ $y }}</option>
                    @endfor
                </select>
            </label>
        </form>
    </div>

    @if ($canManageMonthly)
        <form method="post" action="{{ route('locations.monthly-targets.sync', $location) }}" class="mt-4 overflow-hidden rounded-2xl ring-1 ring-slate-200">
            @csrf
            <input type="hidden" name="year" value="{{ $monthly['year'] }}">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-3 font-medium">Month</th>
                            <th class="px-4 py-3 font-medium text-right">Sales target</th>
                            <th class="px-4 py-3 font-medium text-right">Sales actual</th>
                            <th class="px-4 py-3 font-medium text-right">Collection target</th>
                            <th class="px-4 py-3 font-medium text-right">Collection actual</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($monthly['months'] as $row)
                            @php
                                $m = (int) $row['month'];
                                $target = $row['target'];
                            @endphp
                            <tr class="{{ $row['is_current'] ? 'bg-indigo-50/60' : '' }}">
                                <td class="px-4 py-3 font-medium text-slate-800">
                                    {{ $row['label'] }}
                                    @if ($row['is_current'])
                                        <span class="ml-1 rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-indigo-700">Current</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <input
                                        type="number"
                                        name="months[{{ $m }}][sales_target]"
                                        step="0.01"
                                        min="0"
                                        value="{{ old('months.'.$m.'.sales_target', $target?->sales_target ?? '') }}"
                                        class="w-full min-w-[7rem] rounded-lg border border-slate-300 px-2 py-1.5 text-right text-sm tabular-nums"
                                        placeholder="0.00"
                                    >
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                    {{ number_format($row['sales']['actual'], 2) }}
                                    @if ($row['sales']['pct'] !== null)
                                        <span class="block text-xs text-slate-500">{{ number_format($row['sales']['pct'], 1) }}%</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <input
                                        type="number"
                                        name="months[{{ $m }}][collection_target]"
                                        step="0.01"
                                        min="0"
                                        value="{{ old('months.'.$m.'.collection_target', $target?->collection_target ?? '') }}"
                                        class="w-full min-w-[7rem] rounded-lg border border-slate-300 px-2 py-1.5 text-right text-sm tabular-nums"
                                        placeholder="0.00"
                                    >
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                    {{ number_format($row['collection']['actual'], 2) }}
                                    @if ($row['collection']['pct'] !== null)
                                        <span class="block text-xs text-slate-500">{{ number_format($row['collection']['pct'], 1) }}%</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 text-sm font-semibold text-slate-800">
                        <tr>
                            <td class="px-4 py-3">Year total (targets)</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ number_format(collect($monthly['months'])->sum(fn ($r) => (float) ($r['target']?->sales_target ?? 0)), 2) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                {{ number_format(collect($monthly['months'])->sum(fn ($r) => $r['sales']['actual']), 2) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ number_format(collect($monthly['months'])->sum(fn ($r) => (float) ($r['target']?->collection_target ?? 0)), 2) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                {{ number_format(collect($monthly['months'])->sum(fn ($r) => $r['collection']['actual']), 2) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="border-t border-slate-200 bg-slate-50 px-4 py-3">
                <p class="text-xs text-slate-500">Leave both amounts empty for a month to remove its target. Actuals are read-only.</p>
                <button type="submit" class="mt-3 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Save all monthly targets</button>
            </div>
        </form>
    @else
        <div class="mt-4 overflow-x-auto rounded-2xl ring-1 ring-slate-200">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Month</th>
                        <th class="px-4 py-3 font-medium text-right">Sales target</th>
                        <th class="px-4 py-3 font-medium text-right">Sales actual</th>
                        <th class="px-4 py-3 font-medium text-right">%</th>
                        <th class="px-4 py-3 font-medium text-right">Collection target</th>
                        <th class="px-4 py-3 font-medium text-right">Collection actual</th>
                        <th class="px-4 py-3 font-medium text-right">%</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($monthly['months'] as $row)
                        <tr class="{{ $row['is_current'] ? 'bg-indigo-50/60' : '' }}">
                            <td class="px-4 py-3 font-medium text-slate-800">{{ $row['label'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $row['sales']['target'] > 0 ? number_format($row['sales']['target'], 2) : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ number_format($row['sales']['actual'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-500">{{ $row['sales']['pct'] !== null ? number_format($row['sales']['pct'], 1).'%' : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $row['collection']['target'] > 0 ? number_format($row['collection']['target'], 2) : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ number_format($row['collection']['actual'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-500">{{ $row['collection']['pct'] !== null ? number_format($row['collection']['pct'], 1).'%' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
