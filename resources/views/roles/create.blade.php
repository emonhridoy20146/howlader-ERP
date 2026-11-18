@extends('layouts.app')

@section('title', 'Create Role')
@section('page-title', 'Create Role')

@section('content')
    <div class="space-y-5">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Administration</p>
            <h2 class="mt-2 text-xl font-semibold text-slate-900">Create role</h2>
            <p class="mt-1 text-sm text-slate-500">Name the role, then tick the individual permissions it should unlock.</p>
        </div>

        @include('roles.partials.form', [
            'action' => route('roles.store'),
            'method' => 'POST',
            'role' => null,
            'modules' => $modules,
            'selectedPermissionIds' => $selectedPermissionIds,
            'canAssignPermissions' => $canAssignPermissions,
        ])
    </div>
@endsection
