@extends('layouts.app')
@section('title', 'Create Customer')
@section('page-title', 'Create Customer')
@section('content')
<form action="{{ route('customers.store') }}" method="post" enctype="multipart/form-data" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">@include('customers._form')</form>
@endsection
