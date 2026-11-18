<?php

use App\Http\Controllers\Sms\SmsController;
use Illuminate\Support\Facades\Route;

/*
| SMS module routes. Loaded by App\Providers\SmsServiceProvider inside the
| "web" middleware group. Only users passing the "send-sms" gate (admin /
| super admin / system roles, see config/sms.php) can reach any of them.
*/

Route::middleware(['auth', 'active-user', 'can:send-sms'])
    ->prefix('sms')
    ->name('sms.')
    ->group(function () {
        Route::get('/', [SmsController::class, 'index'])->name('index');
        Route::get('/audience', [SmsController::class, 'audience'])->name('audience');
        Route::get('/customers/search', [SmsController::class, 'searchCustomers'])->name('customers.search');
        Route::get('/balance', [SmsController::class, 'balance'])->name('balance');

        Route::post('/send-single', [SmsController::class, 'sendSingle'])->middleware('throttle:30,1')->name('send-single');
        Route::post('/send-bulk', [SmsController::class, 'sendBulk'])->middleware('throttle:10,1')->name('send-bulk');

        Route::get('/history', [SmsController::class, 'history'])->name('history');
        Route::get('/batches/{batch}', [SmsController::class, 'showBatch'])->name('batches.show');
        Route::get('/batches/{batch}/status', [SmsController::class, 'batchStatus'])->name('batches.status');
        Route::post('/batches/{batch}/resume', [SmsController::class, 'resumeBatch'])->name('batches.resume');
        Route::post('/batches/{batch}/retry-failed', [SmsController::class, 'retryFailed'])->name('batches.retry-failed');

        Route::get('/templates', [SmsController::class, 'templates'])->name('templates.index');
        Route::post('/templates', [SmsController::class, 'storeTemplate'])->name('templates.store');
        Route::put('/templates/{template}', [SmsController::class, 'updateTemplate'])->name('templates.update');
        Route::delete('/templates/{template}', [SmsController::class, 'destroyTemplate'])->name('templates.destroy');
    });
