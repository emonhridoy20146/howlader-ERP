@extends('layouts.app')

@section('title', 'Edit Role')
@section('page-title', 'Edit Role')

@section('content')
    <div class="space-y-5">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Administration</p>
            <h2 class="mt-2 text-xl font-semibold text-slate-900">Edit role</h2>
            <p class="mt-1 text-sm text-slate-500">Saving stores exactly the permissions ticked below — nothing is added or kept behind the scenes.</p>
        </div>

        @include('roles.partials.form', [
            'action' => route('roles.update', $role),
            'method' => 'PUT',
            'role' => $role,
            'modules' => $modules,
            'selectedPermissionIds' => $selectedPermissionIds,
            'canAssignPermissions' => $canAssignPermissions,
        ])
    </div>
@endsection
