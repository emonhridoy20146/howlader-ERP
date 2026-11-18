<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory;

    public const SKIP_WALLET_GL_NOTE_PREFIX = '[no_wallet_gl]';

    public const HISTORICAL_PAYMENT_NOTE_PREFIX = '[historical_payment]';

    public const BACKFILL_NOTE_MARKER = '[backfill]';

    protected $fillable = [
        'payment_number', 'customer_id', 'payment_date', 'payment_method', 'payment_method_id', 'wallet_id', 'amount', 'direction', 'reference_no', 'notes', 'recorded_by', 'referrer_employee_id',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function paymentMethodRef(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The user who recorded (collected) this payment; null when unknown. */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function referrerEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'referrer_employee_id');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function installmentAllocations(): HasMany
    {
        return $this->hasMany(InstallmentPaymentAllocation::class);
    }

    public function returnRefundAllocations(): HasMany
    {
        return $this->hasMany(ReturnRefundAllocation::class);
    }

    public static function formatSkipWalletGlNotes(?string $adminNote = null, bool $backfill = false): string
    {
        $suffix = trim((string) ($adminNote ?? ''));
        if ($suffix === '') {
            $suffix = $backfill
                ? 'Historical transaction backfill (admin, wallet not updated)'
                : 'Historical collection (admin, wallet not updated)';
        }

        $prefix = self::SKIP_WALLET_GL_NOTE_PREFIX.($backfill ? ' '.self::BACKFILL_NOTE_MARKER : '');

        return $prefix.' '.$suffix;
    }

    public static function formatHistoricalPaymentNotes(?string $adminNote = null, bool $backfill = false): string
    {
        $suffix = trim((string) ($adminNote ?? ''));
        if ($suffix === '') {
            $suffix = $backfill
                ? 'Historical transaction backfill (admin)'
                : 'Historical collection (admin)';
        }

        $prefix = self::HISTORICAL_PAYMENT_NOTE_PREFIX.($backfill ? ' '.self::BACKFILL_NOTE_MARKER : '');

        return $prefix.' '.$suffix;
    }

    public function skipsWalletGl(): bool
    {
        return str_starts_with((string) ($this->notes ?? ''), self::SKIP_WALLET_GL_NOTE_PREFIX);
    }

    public function isHistoricalPayment(): bool
    {
        $notes = (string) ($this->notes ?? '');

        return str_starts_with($notes, self::HISTORICAL_PAYMENT_NOTE_PREFIX)
            || str_starts_with($notes, self::SKIP_WALLET_GL_NOTE_PREFIX);
    }

    public function isPaymentBackfill(): bool
    {
        return str_contains((string) ($this->notes ?? ''), self::BACKFILL_NOTE_MARKER);
    }
}
