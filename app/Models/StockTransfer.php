<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use HasFactory;

    /** @var string stock_ledger.reference_type for postings from this domain */
    public const LEDGER_REFERENCE_TYPE = 'StockTransfer';

    protected $fillable = [
        'dizicashier_uuid',
        'transfer_number', 'source_location_id', 'destination_location_id', 'status', 'reason',
        'requested_by', 'approved_by', 'issued_by', 'received_by',
        'source_approved_by', 'destination_approved_by',
        'requested_at', 'approved_at', 'issued_at', 'received_at',
        'source_approved_at', 'destination_approved_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
            'received_at' => 'datetime',
            'source_approved_at' => 'datetime',
            'destination_approved_at' => 'datetime',
        ];
    }

    public function displayStatus(): string
    {
        if ($this->status === 'draft') {
            if ($this->source_approved_at && ! $this->destination_approved_at) {
                return 'Pending destination approval';
            }
            if ($this->destination_approved_at && ! $this->source_approved_at) {
                return 'Pending source approval';
            }

            return 'Draft';
        }

        return ucfirst(str_replace('_', ' ', $this->status));
    }

    public function isFullyApproved(): bool
    {
        return $this->status === 'approved'
            || ($this->source_approved_at !== null && $this->destination_approved_at !== null);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }

    public function transferSerials(): HasMany
    {
        return $this->hasMany(StockTransferSerial::class);
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function sourceApprovedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_approved_by');
    }

    public function destinationApprovedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destination_approved_by');
    }
}
