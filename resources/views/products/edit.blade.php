@extends('layouts.app')

@section('title', 'Edit Product')
@section('page-title', 'Edit Product')

@section('content')
    <form action="{{ route('products.update', $product) }}" method="post" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @method('PUT')
        @include('products._form')
    </form>
@endsection
