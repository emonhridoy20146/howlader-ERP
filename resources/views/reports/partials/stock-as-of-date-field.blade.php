@props(['filters'])
<div class="sm:col-span-2">
    <label class="mb-1 block text-xs font-medium text-slate-600">Stock as of (optional)</label>
    <div class="grid gap-3 sm:grid-cols-2">
        <input
            type="date"
            name="as_of_date"
            value="{{ $filters['as_of_date'] ?? '' }}"
            max="{{ now()->toDateString() }}"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            title="View stock from a previous date"
        >
        <input
            type="time"
            name="as_of_time"
            value="{{ $filters['as_of_time'] ?? '' }}"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            title="Optional time on the selected date"
        >
    </div>
    <p class="mt-1 text-xs text-slate-500">Leave blank for current stock. With only a date, totals include the full day. Add a time to snapshot through that moment. Reserved/on-hold is not reconstructed.</p>
</div>
