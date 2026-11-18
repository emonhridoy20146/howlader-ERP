@extends('layouts.app')
@section('title', 'Edit Supplier')
@section('page-title', 'Edit Supplier')
@section('content')
<form action="{{ route('suppliers.update', $supplier) }}" method="post" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">@method('PUT')@include('suppliers._form')</form>
@endsection
