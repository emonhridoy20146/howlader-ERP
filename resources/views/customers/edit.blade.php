@extends('layouts.app')
@section('title', 'Edit Customer')
@section('page-title', 'Edit Customer')
@section('content')
<form action="{{ route('customers.update', $customer) }}" method="post" enctype="multipart/form-data" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">@method('PUT')@include('customers._form')</form>
@endsection
