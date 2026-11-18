@props(['name', 'options', 'selected' => '', 'placeholder' => 'Select an option', 'clearable' => true, 'tree' => false, 'dispatchChange' => false])

<div x-data="{
    open: false,
    search: '',
    selectedId: '{{ $selected }}',
    options: {{ Js::from($options) }},
    dispatchChange: @js($dispatchChange),
    pick(value) {
        const next = String(value);
        if (this.selectedId === next) {
            this.open = false;
            this.search = '';
            return;
        }
        this.selectedId = next;
        this.open = false;
        this.search = '';
        if (this.dispatchChange) {
            this.$nextTick(() => {
                this.$dispatch('searchable-select-changed', { name: @js($name), value: next });
            });
        }
    },
    clearSelection() {
        this.selectedId = '';
        this.open = false;
        this.search = '';
        if (this.dispatchChange) {
            this.$nextTick(() => {
                this.$dispatch('searchable-select-changed', { name: @js($name), value: '' });
            });
        }
    },
    get filteredOptions() {
        if (this.search === '') {
            return this.options;
        }
        const q = this.search.toLowerCase();
        return this.options.filter(opt => {
            const haystack = (opt.search ?? opt.label ?? '').toLowerCase();
            return haystack.includes(q);
        });
    },
    get selectedLabel() {
        if (!this.selectedId) return '{{ $placeholder }}';
        const opt = this.options.find(opt => opt.value == this.selectedId);
        return opt ? opt.label : '{{ $placeholder }}';
    }
}" class="relative" @click.outside="open = false" @searchable-select-set.window="if ($event.detail?.name === @js($name)) { selectedId = String($event.detail.value ?? ''); }">
    
    <input type="hidden" name="{{ $name }}" x-model="selectedId">

    <button type="button" @click="open = !open" class="flex w-full items-center justify-between rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500" :class="open ? 'ring-1 ring-emerald-500 border-emerald-500' : ''">
        <span x-text="selectedLabel" class="truncate" :class="selectedId ? 'text-slate-800' : 'text-slate-500'"></span>
        <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
    </button>

    <div x-cloak x-show="open" 
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-75"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg overscroll-contain">
        
        <div class="px-2 pb-2 pt-1 sticky top-0 bg-white border-b border-slate-100 mb-1 z-10">
            <input type="text" x-model="search" placeholder="Search..." class="w-full rounded-md border border-slate-300 bg-slate-50 px-3 py-1.5 text-sm focus:border-emerald-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-emerald-500">
        </div>
        
        @if ($clearable)
            <button type="button" @click="clearSelection()" class="w-full px-3 py-2 text-left text-sm text-slate-600 hover:bg-slate-50 transition-colors" :class="!selectedId ? 'bg-emerald-50/50 text-emerald-700 font-medium' : ''">
                {{ $placeholder }}
            </button>
        @endif

        <template x-for="opt in filteredOptions" :key="opt.value">
            <button type="button" @click="pick(opt.value)" class="flex w-full items-center gap-1.5 py-2 pr-3 text-left text-sm text-slate-800 hover:bg-slate-50 transition-colors" :class="selectedId == opt.value ? 'bg-emerald-50/50 text-emerald-700 font-medium' : ''" :style="@js($tree) && opt.depth ? { paddingLeft: (8 + (opt.depth * 14)) + 'px' } : { paddingLeft: '12px' }">
                <span x-show="@js($tree) && opt.depth > 0" class="shrink-0 text-xs text-slate-400" aria-hidden="true">↳</span>
                <span class="min-w-0 truncate" x-text="opt.label"></span>
            </button>
        </template>
        
        <div x-show="filteredOptions.length === 0" class="px-3 py-4 text-center text-sm text-slate-500">
            No matches found.
        </div>
    </div>
</div>
