@php
    $isSystem = $account->exists && $account->is_system;
@endphp
@if($isSystem)
    <p class="text-sm text-slate-600">System account · Code <span class="font-mono font-medium">{{ $account->code }}</span> · Type {{ $formTypes[$account->type] ?? $account->type }}</p>
    <div>
        <label class="text-sm font-medium">Name</label>
        <input name="name" value="{{ old('name', $account->name) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
        @error('name')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div class="flex items-center gap-2">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $account->is_active))>
        <span class="text-sm">Active</span>
    </div>
@else
    <div class="grid gap-4 md:grid-cols-2">
        <div>
            <label class="text-sm font-medium">Code <span class="font-normal text-slate-400">(optional)</span></label>
            <input name="code" value="{{ old('code', $account->code) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm" maxlength="40" placeholder="Leave blank to auto-generate">
            @error('code')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="text-sm font-medium">Type</label>
            <select name="type" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
                @foreach($formTypes as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', $account->type) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('type')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div class="md:col-span-2">
            <label class="text-sm font-medium">Name</label>
            <input name="name" value="{{ old('name', $account->name) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
            @error('name')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div class="flex items-center gap-2 md:col-span-2">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $account->is_active ?? true))>
            <span class="text-sm">Active</span>
        </div>
    </div>
@endif
@if (! $account->exists)
    <p class="text-xs text-slate-500">Wallet cash/bank ledgers are created automatically with each wallet. Use this form for expense and other posting heads.</p>
@endif
