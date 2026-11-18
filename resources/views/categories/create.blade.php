@extends('layouts.app')

@section('title', 'Create Category')
@section('page-title', 'Create Category')

@section('content')
    <form action="{{ route('categories.store') }}" method="post" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @include('categories._form')
    </form>
@endsection
