<?php

namespace App\Http\Controllers\Api\Field;

use App\Http\Controllers\Controller;
use App\Models\FieldPaymentRequest;
use App\Models\User;
use App\Services\FieldApi\FieldApiError;
use App\Services\FieldApi\FieldPaymentIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Payment intake from the field app (idempotent) and lookup by key after a timeout. */
class FieldPaymentController extends Controller
{
    public function store(Request $request, FieldPaymentIntake $intake): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->hasPermission('payment.create')) {
            return (new FieldApiError('FORBIDDEN', 'Integration user lacks payment.create.', 403))->toResponse();
        }

        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '' || strlen($key) > 64 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            return (new FieldApiError('VALIDATION_FAILED', 'Idempotency-Key header is required (≤ 64 safe characters).', 422))->toResponse();
        }

        $v = Validator::make($request->all(), [
            'sales_invoice_id' => ['required', 'integer', 'min:1'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
            'collected_at' => ['required', 'date'],
            'method_type' => ['required', 'in:'.implode(',', FieldPaymentIntake::METHOD_TYPES)],
            'payment_method_id' => ['nullable', 'integer', 'min:1'],
            'mobile_provider' => ['nullable', 'string', 'in:bkash,nagad,rocket'],
            // 2026-11-22 (owner): a field user with no ERP employee is posted by name; the payment's referrer stays empty.
            'collector_employee_id' => ['nullable', 'integer', 'min:1', 'required_without:collector_name'],
            'collector_name' => ['nullable', 'string', 'max:100', 'required_without:collector_employee_id'],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
            'field_receipt_no' => ['required', 'string', 'max:40'],
            'field_collection_id' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        if ($v->fails()) {
            return (new FieldApiError('VALIDATION_FAILED', 'The payment is not valid.', 422, $v->errors()->toArray()))->toResponse();
        }

        try {
            $result = $intake->post($key, $v->validated(), $user);
        } catch (FieldApiError $e) {
            return $e->toResponse();
        }

        return response()->json($result['body'], $result['status']);
    }

    public function byKey(string $key): JsonResponse
    {
        $row = FieldPaymentRequest::query()->where('idempotency_key', $key)->first();
        if (! $row) {
            return (new FieldApiError('NOT_FOUND', 'No payment for this key.', 404))->toResponse();
        }

        return response()->json(['data' => $row->response, 'idempotent_replay' => true]);
    }
}
