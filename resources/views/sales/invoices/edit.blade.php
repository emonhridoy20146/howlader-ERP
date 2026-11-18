@extends('layouts.app')
@section('title', !empty($postedEditMode) ? (!empty($installmentAdminEditMode) ? 'Revise installment invoice' : 'Edit invoice') : 'Edit Draft Invoice')
@section('page-title', !empty($postedEditMode) ? (!empty($installmentAdminEditMode) ? 'Revise active installment invoice' : 'Edit posted invoice') : 'Edit Draft Invoice')
@section('content')
@include('sales.invoices._draft_invoice_form', [
    'draftFormMode' => 'edit',
    'editingInvoice' => $invoice,
    'postedEditMode' => !empty($postedEditMode),
    'installmentAdminEditMode' => !empty($installmentAdminEditMode),
    'initialDraftLines' => $initialDraftLines,
])
@endsection
