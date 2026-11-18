@extends('layouts.app')
@section('title', 'SMS batch #'.$batch->id)
@section('page-title', 'SMS batch #'.$batch->id)
@section('content')

@include('sms.partials.header')

<div x-data="{
		s: @js($batch->only(['status', 'total_recipients', 'sent_count', 'failed_count', 'total_parts'])),
		timer: null,
		init() {
			if (['queued', 'processing'].includes(this.s.status)) {
				this.timer = setInterval(() => this.poll(), 4000);
			}
		},
		async poll() {
			try {
				const res = await fetch(@js(route('sms.batches.status', $batch)), { headers: { Accept: 'application/json' } });
				this.s = await res.json();
				if (! ['queued', 'processing'].includes(this.s.status)) {
					clearInterval(this.timer);
					window.location.reload();
				}
			} catch (e) {}
		},
	}">
	<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
		<x-status-card label="Status"><span class="capitalize" x-text="s.status"></span></x-status-card>
		<x-status-card label="Recipients"><span class="tabular-nums" x-text="s.total_recipients"></span></x-status-card>
		<x-status-card label="Sent"><span class="tabular-nums text-emerald-700" x-text="s.sent_count"></span></x-status-card>
		<x-status-card label="Failed"><span class="tabular-nums text-rose-700" x-text="s.failed_count"></span></x-status-card>
		<x-status-card label="SMS parts used"><span class="tabular-nums" x-text="s.total_parts"></span></x-status-card>
	</div>

	<div class="mb-4 h-2 w-full overflow-hidden rounded-full bg-slate-100" x-show="['queued', 'processing'].includes(s.status)">
		<div class="h-full bg-sky-500 transition-all" :style="'width:' + (s.total_recipients ? Math.round((s.sent_count + s.failed_count) * 100 / s.total_recipients) : 0) + '%'"></div>
	</div>
</div>

<div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 text-sm shadow-sm">
	<dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
		<div><dt class="text-xs text-slate-500">Type</dt><dd class="capitalize">{{ $batch->kind }}</dd></div>
		<div><dt class="text-xs text-slate-500">Scenario</dt><dd>{{ $audiences[$batch->audience]['label'] ?? ucfirst(str_replace('_', ' ', (string) $batch->audience)) }}</dd></div>
		<div><dt class="text-xs text-slate-500">Sender</dt><dd>{{ $batch->sender_type === 'masking' ? 'Masking' : 'Non-masking' }} · <span class="font-mono">{{ $batch->sender_id }}</span></dd></div>
		<div><dt class="text-xs text-slate-500">Sent by</dt><dd>{{ $batch->sender?->name ?? '—' }} · {{ $batch->created_at->format('d M Y, h:i A') }}</dd></div>
		@php($f = collect($batch->filters ?? [])->filter(fn ($v) => $v !== null && $v !== '' && $v !== false))
		@if ($f->isNotEmpty())
			<div class="sm:col-span-2 lg:col-span-4"><dt class="text-xs text-slate-500">Filters</dt>
				<dd class="text-xs text-slate-700">{{ $f->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.(is_bool($v) ? 'yes' : $v))->implode(' · ') }}</dd>
			</div>
		@endif
		<div class="sm:col-span-2 lg:col-span-4"><dt class="text-xs text-slate-500">Message template</dt>
			<dd class="whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-2">{{ $batch->message_template }}</dd>
		</div>
	</dl>

	<div class="mt-4 flex flex-wrap gap-2">
		@if ($batch->pendingCount() > 0 && ! in_array($batch->status, ['queued'], true))
			<form method="post" action="{{ route('sms.batches.resume', $batch) }}" onsubmit="return confirm('Send the {{ $batch->pendingCount() }} SMS that are still pending?')">
				@csrf
				<button class="rounded-lg bg-sky-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-sky-700">Send pending ({{ $batch->pendingCount() }})</button>
			</form>
		@endif
		@if ($batch->failed_count > 0 && in_array($batch->status, ['completed', 'failed'], true))
			<form method="post" action="{{ route('sms.batches.retry-failed', $batch) }}" onsubmit="return confirm('Send the {{ $batch->failed_count }} failed SMS again? This uses SMS balance.')">
				@csrf
				<button class="rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-700">Retry failed ({{ $batch->failed_count }})</button>
			</form>
		@endif
		<a href="{{ route('sms.index') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">Back to SMS</a>
	</div>
</div>

<x-index-table-shell title="Recipients" :meta="$messages->total().' messages'">
	<table class="min-w-[50rem] sm:min-w-full text-sm">
		<thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
			<tr>
				<th class="px-4 py-2">Recipient</th>
				<th class="px-4 py-2">Message</th>
				<th class="px-4 py-2 text-right">Parts</th>
				<th class="px-4 py-2">Status</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($messages as $m)
				<tr class="border-t border-slate-100 align-top">
					<td class="whitespace-nowrap px-4 py-2">
						<div class="font-medium">{{ $m->recipient_name ?? '—' }}</div>
						<div class="font-mono text-xs text-slate-500">{{ $m->phone }}</div>
					</td>
					<td class="max-w-lg px-4 py-2"><p class="whitespace-pre-wrap break-words text-slate-700">{{ $m->message }}</p></td>
					<td class="px-4 py-2 text-right tabular-nums">{{ $m->parts }}</td>
					<td class="whitespace-nowrap px-4 py-2">
						@include('sms.partials.status', ['status' => $m->status])
						@if ($m->status === 'failed' && $m->response_message)
							<div class="mt-1 max-w-[16rem] whitespace-normal text-xs text-rose-700">{{ $m->response_code ? '['.$m->response_code.'] ' : '' }}{{ $m->response_message }}</div>
						@endif
					</td>
				</tr>
			@endforeach
		</tbody>
	</table>
</x-index-table-shell>

{{ $messages->links() }}
@endsection
