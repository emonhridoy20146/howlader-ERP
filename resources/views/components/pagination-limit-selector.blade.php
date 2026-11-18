@php
    $allowed = ['10', '20', '50', '100', '200', '500'];
    $current = request('per_page');
    $current = is_string($current) && in_array($current, $allowed, true) ? $current : '';
@endphp

<div class="mb-2 flex justify-end">
    <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200/95 bg-white px-3 py-2 text-xs font-medium text-slate-600 shadow-sm ring-1 ring-slate-900/[0.06]">
        <span class="text-slate-500">Rows per page</span>
        <select
            class="rounded-md border border-slate-200 bg-slate-50/80 px-2 py-1 text-xs font-medium text-slate-800 shadow-inner focus:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
            onchange="const params = new URLSearchParams(window.location.search); if (this.value === '') { params.delete('per_page'); } else { params.set('per_page', this.value); } params.delete('page'); window.location.search = params.toString();"
        >
            <option value="" @selected($current === '')>Default</option>
            @foreach ($allowed as $value)
                <option value="{{ $value }}" @selected($current === $value)>{{ $value }}</option>
            @endforeach
        </select>
    </label>
</div>
