@php
    $selected = collect($selectedPermissionIds ?? [])->map(fn ($id) => (int) $id)->flip();
    $totalPermissions = $modules->sum(fn ($module) => $module['groups']->sum(fn ($group) => $group['permissions']->count()));
@endphp

<form method="post" action="{{ $action }}" class="space-y-5" x-data="rolePermissionMatrix()">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
            <p class="text-sm font-semibold text-rose-800">Please fix the following errors:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-rose-700">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-2">
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Name</span>
            <input name="name" value="{{ old('name', $role?->name) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" required>
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Slug</span>
            <input name="slug" value="{{ old('slug', $role?->slug) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" required>
            <span class="mt-1 block text-xs text-slate-500">Used in code. A role with the slug <code class="rounded bg-slate-100 px-1">admin</code> bypasses every permission check.</span>
        </label>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Permissions</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Each row is one permission the application checks. What you tick here is exactly what the role gets.
                    <span class="font-medium text-slate-600" x-text="`${selectedCount} of {{ $totalPermissions }} selected`"></span>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <input
                    type="search"
                    x-model="search"
                    placeholder="Filter permissions"
                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                >
                <label class="inline-flex items-center gap-1.5 text-xs text-slate-600">
                    <input type="checkbox" x-model="sensitiveOnly" class="h-3.5 w-3.5 rounded border-slate-300">
                    Sensitive only
                </label>
                @if ($canAssignPermissions)
                    <button type="button" @click="setAll(false)" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">Clear all</button>
                @endif
            </div>
        </div>

        @unless ($canAssignPermissions)
            <div class="border-b border-amber-200 bg-amber-50 px-5 py-3 text-xs text-amber-900">
                You can rename this role but not change its permissions. That needs the <strong>Change role permissions</strong> permission.
            </div>
        @endunless

        <div class="divide-y divide-slate-200">
            @foreach ($modules as $module)
                <section class="p-5" data-permission-module x-show="scopeVisible($el)" x-cloak>
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900">{{ $module['label'] }}</h3>
                            @if ($module['description'] !== '')
                                <p class="mt-0.5 text-xs text-slate-500">{{ $module['description'] }}</p>
                            @endif
                        </div>
                        @if ($canAssignPermissions)
                            <button type="button" @click="toggleScope($el.closest('[data-permission-module]'))" class="shrink-0 self-start rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                Toggle module
                            </button>
                        @endif
                    </div>

                    <div class="mt-4 space-y-4">
                        @foreach ($module['groups'] as $group)
                            <div class="rounded-xl border border-slate-200" data-permission-group x-show="scopeVisible($el)" x-cloak>
                                <div class="flex items-center justify-between gap-3 rounded-t-xl bg-slate-50 px-3 py-2">
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-600">{{ $group['label'] }}</h4>
                                    @if ($canAssignPermissions)
                                        <button type="button" @click="toggleScope($el.closest('[data-permission-group]'))" class="text-xs font-medium text-slate-500 underline hover:text-slate-800">
                                            Toggle
                                        </button>
                                    @endif
                                </div>
                                <div class="grid gap-px bg-slate-200 sm:grid-cols-2">
                                    @foreach ($group['permissions'] as $permission)
                                        <label
                                            class="flex items-start gap-3 bg-white px-3 py-2.5"
                                            data-permission-row
                                            data-search="{{ Str::lower($permission['label'].' '.$permission['slug'].' '.$permission['description']) }}"
                                            data-sensitive="{{ $permission['sensitive'] ? '1' : '0' }}"
                                            x-show="rowVisible($el)"
                                            x-cloak
                                        >
                                            <input
                                                type="checkbox"
                                                name="permission_ids[]"
                                                value="{{ $permission['id'] }}"
                                                class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-slate-900"
                                                @checked($selected->has($permission['id']))
                                                @disabled(! $canAssignPermissions)
                                                @change="recount()"
                                            >
                                            <span class="min-w-0">
                                                <span class="flex flex-wrap items-center gap-1.5">
                                                    <span class="text-sm font-medium text-slate-800">{{ $permission['label'] }}</span>
                                                    @if ($permission['sensitive'])
                                                        <span class="rounded-full bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-700">Sensitive</span>
                                                    @endif
                                                </span>
                                                @if ($permission['description'] !== '')
                                                    <span class="mt-0.5 block text-xs text-slate-500">{{ $permission['description'] }}</span>
                                                @endif
                                                <code class="mt-0.5 block text-[10px] text-slate-400">{{ $permission['slug'] }}</code>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </div>

    <div class="flex items-center gap-2">
        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-700">Save role</button>
        <a href="{{ route('roles.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Cancel</a>
    </div>
</form>

<script>
    function rolePermissionMatrix() {
        return {
            search: '',
            sensitiveOnly: false,
            selectedCount: 0,

            init() {
                this.recount();
            },

            boxes(scope) {
                return Array.from((scope ?? this.$root).querySelectorAll('input[name="permission_ids[]"]'));
            },

            recount() {
                this.selectedCount = this.boxes().filter((box) => box.checked).length;
            },

            matches(row) {
                if (this.sensitiveOnly && row.dataset.sensitive !== '1') {
                    return false;
                }
                const term = this.search.trim().toLowerCase();

                return term === '' || row.dataset.search.includes(term);
            },

            rowVisible(row) {
                return this.matches(row);
            },

            scopeVisible(scope) {
                return Array.from(scope.querySelectorAll('[data-permission-row]')).some((row) => this.matches(row));
            },

            /** Tick everything visible in scope unless it is already fully ticked. */
            toggleScope(scope) {
                const visible = this.boxes(scope).filter((box) => this.matches(box.closest('[data-permission-row]')));
                const target = visible.some((box) => !box.checked);
                visible.forEach((box) => { box.checked = target; });
                this.recount();
            },

            setAll(checked) {
                this.boxes().forEach((box) => { box.checked = checked; });
                this.recount();
            },
        };
    }
</script>
