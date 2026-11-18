<div class="flex flex-wrap items-end gap-3">
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Search</label>
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}"
            placeholder="Invoice, customer, phone…"
            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400 w-52">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Branch</label>
        <select name="branch_id" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
            <option value="">All branches</option>
            @foreach ($filterBranches as $branch)
                <option value="{{ $branch->id }}" @selected(($filters['branch_id'] ?? '') == $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Sort by due date</label>
        <select name="sort" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
            <option value="asc" @selected(($filters['sort'] ?? 'asc') === 'asc')>Oldest first</option>
            <option value="desc" @selected(($filters['sort'] ?? 'asc') === 'desc')>Newest first</option>
        </select>
    </div>
    <button type="submit" class="rounded-lg bg-slate-800 px-4 py-1.5 text-sm font-medium text-white hover:bg-slate-700">Apply</button>
    @if (($filters['q'] ?? '') !== '' || ($filters['branch_id'] ?? '') !== '' || ($filters['sort'] ?? 'asc') !== 'asc')
        <a href="{{ route('reports.installment-overdue') }}" class="rounded-lg border border-slate-300 px-4 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Clear</a>
    @endif
</div>
