<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceDebtWriteoff extends Model
{
    protected $fillable = [
        'sales_invoice_id', 'amount', 'reason', 'actor_user_id',
    ];

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function scheduleAllocations(): HasMany
    {
        return $this->hasMany(InstallmentWriteoffAllocation::class);
    }
}
