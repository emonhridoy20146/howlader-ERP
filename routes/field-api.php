<?php

use App\Http\Controllers\Api\Field\FieldCustomerController;
use App\Http\Controllers\Api\Field\FieldDirectoryController;
use App\Http\Controllers\Api\Field\FieldPaymentController;
use Illuminate\Support\Facades\Route;

/*
| Field Operations integration API v1 (prefix /api/field/v1, bearer token).
| Registered by App\Providers\FieldApiServiceProvider; answers 404 unless FIELD_API_ENABLED=true.
*/

Route::get('/health', [FieldDirectoryController::class, 'health'])->name('health');
Route::get('/branches', [FieldDirectoryController::class, 'branches'])->name('branches');
Route::get('/employees', [FieldDirectoryController::class, 'employees'])->name('employees');
Route::get('/payment-methods', [FieldDirectoryController::class, 'paymentMethods'])->name('payment-methods');

Route::get('/customers', [FieldCustomerController::class, 'index'])->name('customers.index');
Route::get('/customers/{customerId}', [FieldCustomerController::class, 'show'])->whereNumber('customerId')->name('customers.show');
Route::get('/customers/{customerId}/payments', [FieldCustomerController::class, 'payments'])->whereNumber('customerId')->name('customers.payments');

Route::post('/payments', [FieldPaymentController::class, 'store'])->name('payments.store');
Route::get('/payments/by-idempotency-key/{key}', [FieldPaymentController::class, 'byKey'])->name('payments.by-key');
