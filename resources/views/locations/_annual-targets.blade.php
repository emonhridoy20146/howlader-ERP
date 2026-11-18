@php
    $progress = $annualTargetProgress;
    $target = $progress['target'] ?? null;
    $sales = $progress['sales'];
    $collection = $progress['collection'];
    $canManage = auth()->user()?->can('create', \App\Models\BranchAnnualTarget::class);
@endphp

<div class="space-y-6">
    @if (session('status'))
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h3 class="text-base font-semibold text-slate-900">Annual targets</h3>
            <p class="mt-1 text-sm text-slate-600">Year-to-date sales and cash collections vs targets for {{ $targetYear }}.</p>
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

    <div class="grid gap-4 lg:grid-cols-2">
        @include('locations._annual-target-metric', [
            'label' => 'Sales',
            'tone' => 'indigo',
            'metric' => $sales,
        ])
        @include('locations._annual-target-metric', [
            'label' => 'Collection',
            'tone' => 'emerald',
            'metric' => $collection,
        ])
    </div>

    @if ($canManage)
        <div class="overflow-hidden rounded-2xl ring-1 ring-slate-200">
            <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                <h4 class="text-sm font-semibold text-slate-800">{{ $target ? 'Edit' : 'Set' }} targets for {{ $targetYear }}</h4>
            </div>
            <div class="p-4 sm:p-5">
                <form method="post" action="{{ $target ? route('locations.annual-targets.update', [$location, $target]) : route('locations.annual-targets.store', $location) }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    @if ($target)
                        @method('PUT')
                    @else
                        <input type="hidden" name="year" value="{{ $targetYear }}">
                    @endif
                    <div>
                        <label class="text-xs font-medium text-slate-600">Sales target</label>
                        <input type="number" name="sales_target" step="0.01" min="0" value="{{ old('sales_target', $target?->sales_target ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                        @error('sales_target')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600">Collection target</label>
                        <input type="number" name="collection_target" step="0.01" min="0" value="{{ old('collection_target', $target?->collection_target ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                        @error('collection_target')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-medium text-slate-600">Notes (optional)</label>
                        <textarea name="notes" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('notes', $target?->notes ?? '') }}</textarea>
                    </div>
                    <div class="flex flex-wrap gap-2 sm:col-span-2">
                        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Save targets</button>
                        @if ($target)
                            <button type="submit" form="delete-annual-target-{{ $target->id }}" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-medium text-rose-700 hover:bg-rose-100" onclick="return confirm('Remove targets for {{ $targetYear }}?')">Remove</button>
                        @endif
                    </div>
                </form>
                @if ($target)
                    <form id="delete-annual-target-{{ $target->id }}" method="post" action="{{ route('locations.annual-targets.destroy', [$location, $target]) }}" class="hidden">
                        @csrf
                        @method('DELETE')
                    </form>
                @endif
            </div>
        </div>
    @elseif (! $target)
        <p class="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600 ring-1 ring-slate-200">No targets set for {{ $targetYear }}.</p>
    @endif

    @if (count($annualTargetHistory) > 0)
        <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Year</th>
                        <th class="px-4 py-3 font-medium text-right">Sales target</th>
                        <th class="px-4 py-3 font-medium text-right">Collection target</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($annualTargetHistory as $row)
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-800">{{ $row->year }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row->sales_target, 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row->collection_target, 2) }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('locations.show', ['location' => $location, 'tab' => 'targets', 'target_year' => $row->year]) }}#location-activity" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">View progress</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
