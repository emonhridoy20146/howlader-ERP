@extends('layouts.app')
@section('title', 'Create service job')
@section('page-title', 'Create service job')
@section('content')
<div class="mb-4">
	<a href="{{ route('service-jobs.index') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">← Back to service jobs</a>
</div>

<form action="{{ route('service-jobs.store') }}" method="post" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
	@csrf

	@if ($errors->any())
		<div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
			<ul class="list-disc pl-5">
				@foreach ($errors->all() as $error)
					<li>{{ $error }}</li>
				@endforeach
			</ul>
		</div>
	@endif

	@include('service-jobs._form-fields', [
		'mode' => 'create',
		'service_job' => null,
		'locations' => $locations,
		'customers' => $customers,
		'assignees' => $assignees,
		'defaultBranchId' => $defaultBranchId,
	])

	<div class="mt-6 flex flex-wrap gap-2">
		<button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Create service job</button>
		<a href="{{ route('service-jobs.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Cancel</a>
	</div>
</form>
@endsection
