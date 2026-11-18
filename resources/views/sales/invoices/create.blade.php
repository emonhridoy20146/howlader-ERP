@extends('layouts.app')
@section('title', 'Create Sales Invoice')
@section('page-title', 'Create Sales Invoice')
@section('content')
@include('sales.invoices._draft_invoice_form', [
    'draftFormMode' => 'create',
    'editingInvoice' => null,
    'initialDraftLines' => [],
])
@endsection
