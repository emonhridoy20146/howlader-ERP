@extends('layouts.app')
@section('title', 'Edit Location')
@section('page-title', 'Edit Location')
@section('content')
<form action="{{ route('locations.update', $location) }}" method="post" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">@method('PUT')@include('locations._form')</form>
@endsection
