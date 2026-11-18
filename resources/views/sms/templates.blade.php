@extends('layouts.app')
@section('title', 'SMS templates')
@section('page-title', 'SMS templates')
@section('content')

@include('sms.partials.header')

@php
	$categories = ['general' => 'General'] + collect($audiences)->map(fn ($a) => $a['label'])->all();
@endphp

<div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_24rem]">
	<div class="space-y-3">
		@forelse ($templates as $t)
			<details class="group rounded-2xl border border-slate-200 bg-white shadow-sm">
				<summary class="flex cursor-pointer items-start gap-3 px-4 py-3">
					<div class="min-w-0 flex-1">
						<div class="flex flex-wrap items-center gap-2">
							<span class="font-semibold text-slate-900">{{ $t->name }}</span>
							<span class="rounded-full bg-slate-100 px-2 py-0.5 text-[0.7rem] text-slate-600">{{ $categories[$t->category] ?? $t->category }}</span>
							@unless ($t->is_active)
								<span class="rounded-full bg-amber-50 px-2 py-0.5 text-[0.7rem] text-amber-700">Inactive</span>
							@endunless
						</div>
						<p class="mt-1 whitespace-pre-wrap break-words text-sm text-slate-600">{{ $t->body }}</p>
					</div>
					<span class="text-xs text-sky-700 group-open:hidden">Edit</span>
				</summary>
				<form method="post" action="{{ route('sms.templates.update', $t) }}" class="space-y-3 border-t border-slate-100 px-4 py-3">
					@csrf @method('put')
					@include('sms.partials.template-fields', ['t' => $t])
					<div class="flex justify-between">
						<button class="rounded-lg bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white">Save</button>
						<button type="submit" form="delete-template-{{ $t->id }}" class="text-sm text-rose-700 hover:underline">Delete</button>
					</div>
				</form>
				<form id="delete-template-{{ $t->id }}" method="post" action="{{ route('sms.templates.destroy', $t) }}" onsubmit="return confirm('Delete this template?')" class="hidden">
					@csrf @method('delete')
				</form>
			</details>
		@empty
			<p class="rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No templates yet.</p>
		@endforelse
	</div>

	<div>
		<form method="post" action="{{ route('sms.templates.store') }}" class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:sticky lg:top-4">
			@csrf
			<h2 class="text-sm font-semibold text-slate-900">New template</h2>
			@include('sms.partials.template-fields', ['t' => null])
			<button class="w-full rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Add template</button>
			<div class="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
				<p class="mb-1 font-semibold">Placeholders</p>
				<ul class="grid grid-cols-1 gap-0.5">
					@foreach ($placeholders as $key => $label)
						<li><code class="font-mono">{{ '{'.$key.'}' }}</code> — {{ $label }}</li>
					@endforeach
				</ul>
				<p class="mt-2">Bangla SMS: 70 characters for one SMS, 67 per part after that. English: 160 / 153.</p>
			</div>
		</form>
	</div>
</div>
@endsection
