<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsBatch extends Model
{
    protected $fillable = [
        'request_token', 'kind', 'audience', 'filters', 'message_template', 'sender_type', 'sender_id',
        'total_recipients', 'sent_count', 'failed_count', 'total_parts', 'status', 'sent_by',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function pendingCount(): int
    {
        return max(0, (int) $this->total_recipients - (int) $this->sent_count - (int) $this->failed_count);
    }
}
