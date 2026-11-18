<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountsReceivableLedger extends Model
{
    use HasFactory;

    protected $table = 'accounts_receivable_ledger';

    protected $fillable = [
        'customer_id', 'event_time', 'event_type', 'amount_delta', 'reference_type', 'reference_id', 'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'event_time' => 'datetime',
        ];
    }
}
