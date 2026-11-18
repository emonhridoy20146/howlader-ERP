@extends('layouts.app')
@section('title', 'Edit '.$service_job->job_number)
@section('page-title', 'Edit service job')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
	<a href="{{ route('service-jobs.show', $service_job) }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">← Back to job</a>
</div>

<form action="{{ route('service-jobs.update', $service_job) }}" method="post" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
	@csrf
	@method('PUT')

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
		'mode' => 'edit',
		'service_job' => $service_job,
		'locations' => $locations,
		'customers' => $customers,
		'assignees' => $assignees,
		'defaultBranchId' => null,
	])

	<div class="mt-6 flex flex-wrap gap-2">
		<button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Save changes</button>
		<a href="{{ route('service-jobs.show', $service_job) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Cancel</a>
	</div>
</form>
@endsection
