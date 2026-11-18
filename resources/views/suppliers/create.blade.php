@extends('layouts.app')
@section('title', 'Create Supplier')
@section('page-title', 'Create Supplier')
@section('content')
<form action="{{ route('suppliers.store') }}" method="post" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">@include('suppliers._form')</form>
@endsection
