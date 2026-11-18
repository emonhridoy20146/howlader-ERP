@props([
	'existingUrl' => null,
	'label' => null,
])
<div class="space-y-2 rounded-xl border border-slate-200 bg-slate-50/50 p-4 md:col-span-2">
	<span class="text-sm font-medium text-slate-700">{{ $label ?? __('Photo') }}</span>
	@if ($existingUrl)
		<div class="flex flex-wrap items-center gap-4">
			<img src="{{ $existingUrl }}" alt="" class="h-20 w-20 shrink-0 rounded-lg bg-white object-cover ring-1 ring-slate-200">
			<label class="inline-flex cursor-pointer items-center gap-2 text-xs text-slate-600">
				<input type="checkbox" name="remove_photo" value="1" @checked(old('remove_photo'))>
				<span>{{ __('Remove current photo') }}</span>
			</label>
		</div>
	@endif
	<input type="file" name="photo" accept="image/*" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-slate-800">
	<p class="text-xs text-slate-500">{{ __('JPEG, PNG, WebP or GIF. Max 2 MB.') }}</p>
	@error('photo')
		<p class="text-xs text-rose-600">{{ $message }}</p>
	@enderror
</div>
