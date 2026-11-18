@extends('layouts.app')

@section('title', 'Create Brand')
@section('page-title', 'Create Brand')

@section('content')
    <form action="{{ route('brands.store') }}" method="post" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @include('brands._form')
    </form>
@endsection
