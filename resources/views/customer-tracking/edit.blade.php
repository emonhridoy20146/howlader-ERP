@extends('layouts.app')

@section('title', 'Edit tracking — '.$customer->name)
@section('page-title', 'Edit Customer Tracking')

@section('content')
@include('customer-tracking.partials.tracking-panel', [
    'customer' => $customer,
    'mapLat' => $mapLat,
    'mapLng' => $mapLng,
    'mapZoom' => $mapZoom,
    'showBackLink' => true,
    'backUrl' => route('customer-tracking.show', $customer),
    'backLabel' => 'Back to view',
])
@endsection
