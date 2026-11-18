<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_number', 'serial_item_id', 'customer_id', 'branch_id', 'type', 'status', 'complaint', 'diagnosis',
        'work_done', 'repair_cost', 'assigned_to', 'intake_date', 'promised_date', 'completed_date',
    ];

    protected function casts(): array
    {
        return [
            'intake_date' => 'date',
            'promised_date' => 'date',
            'completed_date' => 'date',
        ];
    }

    public function serialItem(): BelongsTo
    {
        return $this->belongsTo(SerialItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'branch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
