@extends('layouts.app')
@section('title', 'Location '.$location->code)
@section('page-title', 'Location Details')
@section('content')
@include('locations._show-body')
@endsection
