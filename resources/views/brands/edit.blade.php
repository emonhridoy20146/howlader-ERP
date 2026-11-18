@extends('layouts.app')

@section('title', 'Edit Brand')
@section('page-title', 'Edit Brand')

@section('content')
    <form action="{{ route('brands.update', $brand) }}" method="post" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @method('PUT')
        @include('brands._form')
    </form>
@endsection
