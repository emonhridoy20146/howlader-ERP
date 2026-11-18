<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records exactly how much of a debt write-off was applied to each installment
 * schedule, so the write-off can later be removed precisely (see {@see \App\Services\DebtWriteoffService::reverseInvoiceDebtWriteoff}).
 */
class InstallmentWriteoffAllocation extends Model
{
    protected $fillable = [
        'installment_schedule_id', 'invoice_debt_writeoff_id', 'amount',
    ];

    public function installmentSchedule(): BelongsTo
    {
        return $this->belongsTo(InstallmentSchedule::class);
    }

    public function invoiceDebtWriteoff(): BelongsTo
    {
        return $this->belongsTo(InvoiceDebtWriteoff::class);
    }
}
