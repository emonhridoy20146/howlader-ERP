@php
    $selectedRoles = collect(old('role_ids', $user?->roles?->pluck('id')->all() ?? []))->map(fn ($id) => (int) $id)->all();
    $selectedLocations = collect(old('location_ids', $user?->accessibleLocations?->pluck('id')->all() ?? []))->map(fn ($id) => (int) $id)->all();
    $isEdit = (bool) $user;
@endphp

<form method="post" action="{{ $action }}" class="space-y-6" enctype="multipart/form-data">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 shadow-sm">
            <p class="text-sm font-semibold text-rose-800">Please fix the following errors</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-rose-700">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Profile --}}
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white px-5 py-4">
            <h3 class="text-sm font-semibold text-slate-900">Profile</h3>
            <p class="mt-0.5 text-xs text-slate-500">Login identity and contact details.</p>
        </div>
        <div class="grid gap-4 p-5 md:grid-cols-2">
            <x-model-photo-upload :existing-url="$user?->photoUrl()" :label="__('Profile photo')" />
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Username</span>
                <input name="name" value="{{ old('name', $user?->name) }}" autocomplete="username" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" required>
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Full name</span>
                <input name="full_name" value="{{ old('full_name', $user?->full_name) }}" autocomplete="name" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="Optional display name">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Email</span>
                <input type="email" name="email" value="{{ old('email', $user?->email) }}" autocomplete="email" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" required>
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Phone</span>
                <input name="phone" value="{{ old('phone', $user?->phone) }}" autocomplete="tel" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200">
            </label>
            <label class="block md:col-span-2">
                <span class="text-sm font-medium text-slate-700">Password</span>
                @if ($isEdit)
                    <span class="ml-1 text-xs font-normal text-slate-500">Leave empty to keep the current password.</span>
                @endif
                <input type="password" name="password" autocomplete="new-password" class="mt-1 w-full max-w-md rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" {{ $isEdit ? '' : 'required minlength="8"' }}>
            </label>
        </div>
    </section>

    {{-- Roles --}}
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 bg-gradient-to-r from-indigo-50/80 to-white px-5 py-4">
            <h3 class="text-sm font-semibold text-slate-900">Roles</h3>
            <p class="mt-0.5 text-xs text-slate-500">Each role grants a set of permissions. Users can have more than one role.</p>
        </div>
        <fieldset class="p-5">
            <legend class="sr-only">Role assignment</legend>
            @if ($roles->isEmpty())
                <p class="text-sm text-slate-500">No roles defined yet.</p>
            @else
                <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($roles as $role)
                        <li>
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50/50 p-3 transition hover:border-indigo-300 hover:bg-white has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50/40 has-[:checked]:ring-1 has-[:checked]:ring-indigo-200">
                                <input
                                    type="checkbox"
                                    name="role_ids[]"
                                    value="{{ $role->id }}"
                                    class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                    @checked(in_array($role->id, $selectedRoles, true))
                                >
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-slate-900">{{ $role->name }}</span>
                                    <span class="mt-0.5 block font-mono text-xs text-slate-500">{{ $role->slug }}</span>
                                </span>
                            </label>
                        </li>
                    @endforeach
                </ul>
            @endif
        </fieldset>
    </section>

    {{-- Locations --}}
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 bg-gradient-to-r from-emerald-50/80 to-white px-5 py-4">
            <h3 class="text-sm font-semibold text-slate-900">Accessible locations</h3>
            <p class="mt-0.5 text-xs text-slate-500">Non-admin users only see inventory and reports for branches they can access. Admins can see all locations regardless of this list.</p>
        </div>
        <fieldset class="p-5">
            <legend class="sr-only">Location access</legend>
            @if ($locations->isEmpty())
                <p class="text-sm text-slate-500">No active locations in the system.</p>
            @else
                <ul class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($locations as $location)
                        <li>
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50/50 p-3 transition hover:border-emerald-300 hover:bg-white has-[:checked]:border-emerald-400 has-[:checked]:bg-emerald-50/40 has-[:checked]:ring-1 has-[:checked]:ring-emerald-200">
                                <input
                                    type="checkbox"
                                    name="location_ids[]"
                                    value="{{ $location->id }}"
                                    class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                    @checked(in_array($location->id, $selectedLocations, true))
                                >
                                <span class="min-w-0">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-medium text-slate-800">{{ $location->name }}</span>
                                        @if ($location->type)
                                            <span class="rounded-full bg-slate-200/80 px-2 py-0.5 text-[10px] font-medium uppercase text-slate-600">{{ $location->type }}</span>
                                        @endif
                                    </span>
                                    <span class="mt-0.5 block text-sm font-medium text-slate-900">{{ $location->name }}</span>
                                </span>
                            </label>
                        </li>
                    @endforeach
                </ul>
            @endif
        </fieldset>
    </section>

    {{-- Status & actions --}}
    <section class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        <label class="inline-flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 transition hover:bg-slate-100 has-[:checked]:border-emerald-300 has-[:checked]:bg-emerald-50/60">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" @checked(old('is_active', $user?->is_active ?? true))>
            <span>
                <span class="block font-medium">Account active</span>
                <span class="block text-xs text-slate-500">Inactive users cannot sign in.</span>
            </span>
        </label>
        <div class="flex flex-wrap items-center gap-2 sm:justify-end">
            <a href="{{ route('users.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-slate-900 px-5 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2">
                {{ $isEdit ? 'Save changes' : 'Create user' }}
            </button>
        </div>
    </section>
</form>
