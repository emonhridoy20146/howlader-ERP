@php
	$tabs = [
		['route' => 'sms.index', 'label' => 'Send SMS', 'match' => ['sms.index']],
		['route' => 'sms.history', 'label' => 'History', 'match' => ['sms.history', 'sms.batches.*']],
		['route' => 'sms.templates.index', 'label' => 'Templates', 'match' => ['sms.templates.*']],
	];
@endphp

<div class="mb-4 flex flex-wrap items-center gap-2 border-b border-slate-200 pb-3">
	@foreach ($tabs as $tab)
		<a href="{{ route($tab['route']) }}"
			@class([
				'rounded-lg px-3 py-1.5 text-sm font-medium transition',
				'bg-slate-900 text-white' => request()->routeIs(...$tab['match']),
				'text-slate-600 hover:bg-slate-100' => ! request()->routeIs(...$tab['match']),
			])>{{ $tab['label'] }}</a>
	@endforeach
	<span class="ml-auto text-xs text-slate-500">SMS is sent only when you press Send — nothing is automatic.</span>
</div>

@if (session('status'))
	<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
@endif
@if (session('error'))
	<div class="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ session('error') }}</div>
@endif
@if ($errors->any())
	<div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
		<ul class="list-disc pl-5">
			@foreach ($errors->all() as $error)
				<li>{{ $error }}</li>
			@endforeach
		</ul>
	</div>
@endif
