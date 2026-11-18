@extends('layouts.app')
@section('title', 'Create Location')
@section('page-title', 'Create Location')
@section('content')
<form action="{{ route('locations.store') }}" method="post" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">@include('locations._form')</form>
@endsection
