@props([
	'resetUrl',
	'submitLabel' => 'Filter',
])

<div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 pt-3">
	<button type="submit" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ $submitLabel }}</button>
	<a href="{{ $resetUrl }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Reset</a>
</div>
