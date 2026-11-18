@extends('layouts.app')

@section('title', 'Create Product')
@section('page-title', 'Create Product')

@section('content')
    <form action="{{ route('products.store') }}" method="post" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @include('products._form')
    </form>
@endsection
