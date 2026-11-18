<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends Model
{
    protected $fillable = [
        'sms_batch_id', 'customer_id', 'recipient_name', 'phone', 'message', 'sender_type', 'sender_id',
        'is_unicode', 'characters', 'parts', 'status', 'response_code', 'response_message',
        'provider_response', 'audience', 'sent_by', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'is_unicode' => 'boolean',
            'provider_response' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SmsBatch::class, 'sms_batch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
