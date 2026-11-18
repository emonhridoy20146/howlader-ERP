<label class="block text-xs font-medium text-slate-600">Name
	<input type="text" name="name" value="{{ $t?->name }}" required maxlength="120" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
</label>
<label class="block text-xs font-medium text-slate-600">Scenario
	<select name="category" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
		@foreach ($categories as $key => $label)
			<option value="{{ $key }}" @selected(($t?->category ?? 'general') === $key)>{{ $label }}</option>
		@endforeach
	</select>
</label>
<label class="block text-xs font-medium text-slate-600">Message
	<textarea name="body" rows="5" required maxlength="1000" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ $t?->body }}</textarea>
</label>
<label class="flex items-center gap-2 text-xs font-medium text-slate-700">
	<input type="hidden" name="is_active" value="0">
	<input type="checkbox" name="is_active" value="1" @checked($t?->is_active ?? true) class="rounded border-slate-300">
	Active (shown in the template list)
</label>
