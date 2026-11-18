@extends('layouts.app')

@section('title', 'Edit Category')
@section('page-title', 'Edit Category')

@section('content')
    <form action="{{ route('categories.update', $category) }}" method="post" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        @method('PUT')
        @include('categories._form')
    </form>
@endsection
