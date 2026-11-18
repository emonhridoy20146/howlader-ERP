@extends('layouts.app')
@section('title', 'SMS history')
@section('page-title', 'SMS history')
@section('content')

@include('sms.partials.header')

<form method="get" action="{{ route('sms.history') }}" class="mb-4 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-6">
	<input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Phone, name or text" class="rounded-lg border border-slate-300 px-3 py-2 text-sm lg:col-span-2">
	<select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
		<option value="">All status</option>
		@foreach (['sent', 'failed', 'pending'] as $s)
			<option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucfirst($s) }}</option>
		@endforeach
	</select>
	<select name="sender_type" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
		<option value="">Masking + non-masking</option>
		<option value="non_masking" @selected($filters['sender_type'] === 'non_masking')>Non-masking</option>
		<option value="masking" @selected($filters['sender_type'] === 'masking')>Masking</option>
	</select>
	<select name="audience" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
		<option value="">All scenarios</option>
		<option value="single" @selected($filters['audience'] === 'single')>Single / custom</option>
		@foreach ($audiences as $key => $def)
			<option value="{{ $key }}" @selected($filters['audience'] === $key)>{{ $def['label'] }}</option>
		@endforeach
	</select>
	<div class="flex gap-2">
		<input type="date" name="from" value="{{ $filters['from'] }}" class="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm">
		<input type="date" name="to" value="{{ $filters['to'] }}" class="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm">
	</div>
	<div class="flex gap-2 lg:col-span-6">
		<button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Filter</button>
		<a href="{{ route('sms.history') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm">Reset</a>
	</div>
</form>

<x-index-table-shell title="Messages" :meta="$messages->total().' messages'">
	<table class="min-w-[60rem] sm:min-w-full text-sm">
		<thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
			<tr>
				<th class="px-4 py-2">When</th>
				<th class="px-4 py-2">Recipient</th>
				<th class="px-4 py-2">Message</th>
				<th class="px-4 py-2">Sender</th>
				<th class="px-4 py-2 text-right">Parts</th>
				<th class="px-4 py-2">Status</th>
				<th class="px-4 py-2">By</th>
			</tr>
		</thead>
		<tbody>
			@forelse ($messages as $m)
				<tr class="border-t border-slate-100 align-top hover:bg-slate-50/70">
					<td class="whitespace-nowrap px-4 py-2 text-xs text-slate-600">
						{{ ($m->sent_at ?? $m->created_at)->format('d M Y, h:i A') }}
						@if ($m->sms_batch_id)
							<a href="{{ route('sms.batches.show', $m->sms_batch_id) }}" class="block text-blue-700 hover:underline">Batch #{{ $m->sms_batch_id }}</a>
						@endif
					</td>
					<td class="whitespace-nowrap px-4 py-2">
						<div class="font-medium text-slate-900">{{ $m->recipient_name ?? '—' }}</div>
						<div class="font-mono text-xs text-slate-500">{{ $m->phone }}</div>
					</td>
					<td class="max-w-md px-4 py-2 text-slate-700"><p class="whitespace-pre-wrap break-words">{{ $m->message }}</p></td>
					<td class="whitespace-nowrap px-4 py-2 text-xs">{{ $m->sender_type === 'masking' ? 'Masking' : 'Non-masking' }}<div class="font-mono text-slate-500">{{ $m->sender_id }}</div></td>
					<td class="px-4 py-2 text-right tabular-nums">{{ $m->parts }}@if ($m->is_unicode)<span class="block text-[0.65rem] text-indigo-600">Unicode</span>@endif</td>
					<td class="whitespace-nowrap px-4 py-2">
						@include('sms.partials.status', ['status' => $m->status])
						@if ($m->status === 'failed' && $m->response_message)
							<div class="mt-1 max-w-[14rem] whitespace-normal text-xs text-rose-700">{{ $m->response_code ? '['.$m->response_code.'] ' : '' }}{{ $m->response_message }}</div>
						@endif
					</td>
					<td class="whitespace-nowrap px-4 py-2 text-xs text-slate-600">{{ $m->sender?->name ?? '—' }}</td>
				</tr>
			@empty
				<tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No messages found.</td></tr>
			@endforelse
		</tbody>
	</table>
</x-index-table-shell>

{{ $messages->links() }}
@endsection
