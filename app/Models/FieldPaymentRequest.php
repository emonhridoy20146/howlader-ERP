<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One successful payment posted through the field integration API, keyed by the
 * client's Idempotency-Key (generated once on the staff phone). `payment_id` is a plain
 * column (no FK) so the row survives when the office later removes the payment.
 */
class FieldPaymentRequest extends Model
{
    protected $fillable = [
        'idempotency_key', 'request_hash', 'payment_id', 'sales_invoice_id', 'customer_id',
        'field_collection_id', 'field_receipt_no', 'response',
    ];

    protected function casts(): array
    {
        return ['response' => 'array'];
    }
}
