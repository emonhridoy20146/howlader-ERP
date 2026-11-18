@php
	$mode = $mode ?? 'create';
	$isEdit = $mode === 'edit';
	$job = $service_job ?? null;
@endphp

<div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
	@if ($isEdit && $job)
		<div class="lg:col-span-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
			<p class="font-mono text-xs text-slate-500">Job</p>
			<p class="mt-0.5 font-mono font-semibold text-slate-900">{{ $job->job_number }}</p>
			<dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
				<div>
					<dt class="text-xs font-medium text-slate-500">Branch</dt>
					<dd class="mt-0.5 text-slate-800">{{ $job->branch?->name }}</dd>
				</div>
				<div>
					<dt class="text-xs font-medium text-slate-500">Serial</dt>
					<dd class="mt-0.5 font-mono text-slate-800">{{ $job->serialItem?->serial_number ?? '—' }}</dd>
				</div>
				<div>
					<dt class="text-xs font-medium text-slate-500">Product</dt>
					<dd class="mt-0.5 text-slate-800">{{ $job->serialItem?->product?->name ?? '—' }}</dd>
				</div>
			</dl>
		</div>
	@endif

	@if (! $isEdit)
		<div class="lg:col-span-3 rounded-lg border border-slate-200 bg-white p-4"
			x-data="{
				branchId: @js(old('branch_id', $defaultBranchId)),
				serialId: @js(old('serial_item_id', '')),
				serialQuery: '',
				serialResults: [],
				searching: false,
				searchUrl: @js(route('service-jobs.serials.search')),
				async searchSerials() {
					if (!this.branchId || this.serialQuery.trim().length < 1) { this.serialResults = []; return; }
					this.searching = true;
					const u = new URL(this.searchUrl);
					u.searchParams.set('branch_id', this.branchId);
					u.searchParams.set('q', this.serialQuery.trim());
					try {
						const r = await fetch(u.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
						this.serialResults = await r.json();
					} catch (e) {
						this.serialResults = [];
					}
					this.searching = false;
				},
				pickSerial(row) {
					this.serialId = String(row.id);
					this.serialResults = [];
					this.serialQuery = '';
				},
				clearSerial() {
					this.serialId = '';
				}
			}"
			x-init="$watch('branchId', () => { clearSerial(); serialResults = []; serialQuery = ''; })"
		>
			<p class="text-sm font-medium text-slate-800">Serial at branch</p>
			<p class="mt-1 text-xs text-slate-500">Choose the service branch, search by serial number, ID, or manufacturer serial, then pick one row.</p>
			<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
				<div>
					<label for="branch_id" class="text-sm font-medium text-slate-700">Branch <span class="text-rose-600">*</span></label>
					<select id="branch_id" name="branch_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" x-model="branchId" required>
						<option value="">Select branch</option>
						@foreach ($locations as $loc)
							<option value="{{ $loc->id }}" @selected((string) old('branch_id', $defaultBranchId) === (string) $loc->id)>{{ $loc->name }}</option>
						@endforeach
					</select>
					@error('branch_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
				</div>
				<div class="sm:col-span-2">
					<label class="text-sm font-medium text-slate-700">Find serial</label>
					<div class="mt-1 flex flex-wrap gap-2">
						<input type="search" x-model="serialQuery" @keydown.enter.prevent="searchSerials()" class="min-w-[12rem] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Type serial #, MFG serial, or ID" autocomplete="off">
						<button type="button" @click="searchSerials()" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50" :disabled="searching || !branchId">Search</button>
					</div>
					<input type="hidden" name="serial_item_id" x-bind:value="serialId">
					@error('serial_item_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
					<p class="mt-2 text-xs text-slate-500" x-show="serialId">Selected serial ID: <span class="font-mono font-medium text-slate-800" x-text="serialId"></span> <button type="button" class="text-blue-700 hover:underline" @click="clearSerial()">Clear</button></p>
					<div class="mt-2 max-h-48 overflow-y-auto rounded-lg border border-slate-200" x-show="serialResults.length">
						<template x-for="row in serialResults" :key="row.id">
							<button type="button" class="flex w-full flex-col gap-0.5 border-b border-slate-100 px-3 py-2 text-left text-sm last:border-b-0 hover:bg-slate-50" @click="pickSerial(row)">
								<span class="font-mono text-xs text-slate-900" x-text="row.serial_number"></span>
								<span class="text-xs text-slate-600" x-text="row.product_name || ''"></span>
								<span class="text-xs text-slate-500" x-text="'#' + row.id + ' · ' + (row.status || '')"></span>
							</button>
						</template>
					</div>
				</div>
			</div>
		</div>
	@endif

	<div>
		<label for="customer_id" class="text-sm font-medium text-slate-700">Customer (optional)</label>
		<select id="customer_id" name="customer_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
			<option value="">—</option>
			@foreach ($customers as $c)
				<option value="{{ $c->id }}" @selected((string) old('customer_id', $job?->customer_id) === (string) $c->id)>{{ $c->name }} ({{ $c->customer_code }})</option>
			@endforeach
		</select>
		@error('customer_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
	</div>
	<div>
		<label for="type" class="text-sm font-medium text-slate-700">Type <span class="text-rose-600">*</span></label>
		<select id="type" name="type" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
			@foreach (['warranty_repair' => 'Warranty repair', 'paid_repair' => 'Paid repair', 'inspection' => 'Inspection'] as $val => $label)
				<option value="{{ $val }}" @selected(old('type', $job?->type) === $val)>{{ $label }}</option>
			@endforeach
		</select>
		@error('type')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
	</div>
	<div>
		<label for="status" class="text-sm font-medium text-slate-700">Status <span class="text-rose-600">*</span></label>
		<select id="status" name="status" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
			@foreach (['intake' => 'Intake', 'diagnosing' => 'Diagnosing', 'awaiting_parts' => 'Awaiting parts', 'repairing' => 'Repairing', 'done' => 'Done', 'returned_to_customer' => 'Returned to customer', 'unrepairable' => 'Unrepairable'] as $val => $label)
				<option value="{{ $val }}" @selected(old('status', $job?->status) === $val)>{{ $label }}</option>
			@endforeach
		</select>
		@error('status')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
	</div>
	<div>
		<label for="intake_date" class="text-sm font-medium text-slate-700">Intake date <span class="text-rose-600">*</span></label>
		<input type="date" id="intake_date" name="intake_date" value="{{ old('intake_date', $job?->intake_date?->toDateString() ?? now()->toDateString()) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
		@error('intake_date')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
	</div>
	<div>
		<label for="promised_date" class="text-sm font-medium text-slate-700">Promised date</label>
		<input type="date" id="promised_date" name="promised_date" value="{{ old('promised_date', $job?->promised_date?->toDateString()) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
		@error('promised_date')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
	</div>
	<div>
		<label for="completed_date" class="text-sm font-medium text-slate-700">Completed date</label>
		<input type="date" id="completed_date" name="completed_date" value="{{ old('completed_date', $job?->completed_date?->toDateString()) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
		@error('completed_date')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
	</div>
	<div>
		<label for="repair_cost" class="text-sm font-medium text-slate-700">Repair cost</label>
		<input type="number" step="0.01" min="0" id="repair_cost" name="repair_cost" value="{{ old('repair_cost', $job?->repair_cost ?? '0') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
		@error('repair_cost')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
	</div>
	<div>
		<label for="assigned_to" class="text-sm font-medium text-slate-700">Assigned to</label>
		<select id="assigned_to" name="assigned_to" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
			<option value="">—</option>
			@foreach ($assignees as $u)
				<option value="{{ $u->id }}" @selected((string) old('assigned_to', $job?->assigned_to) === (string) $u->id)>{{ $u->displayName() }}</option>
			@endforeach
		</select>
		@error('assigned_to')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
	</div>
</div>

<div class="mt-4">
	<label for="complaint" class="text-sm font-medium text-slate-700">Complaint <span class="text-rose-600">*</span></label>
	<textarea id="complaint" name="complaint" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>{{ old('complaint', $job?->complaint) }}</textarea>
	@error('complaint')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
</div>

<div class="mt-4">
	<label for="diagnosis" class="text-sm font-medium text-slate-700">Diagnosis</label>
	<textarea id="diagnosis" name="diagnosis" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('diagnosis', $job?->diagnosis) }}</textarea>
	@error('diagnosis')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
</div>

<div class="mt-4">
	<label for="work_done" class="text-sm font-medium text-slate-700">Work done</label>
	<textarea id="work_done" name="work_done" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('work_done', $job?->work_done) }}</textarea>
	@error('work_done')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
</div>
