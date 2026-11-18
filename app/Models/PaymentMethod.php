<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'code',
        'name',
        'type',
        'account_name',
        'account_number',
        'bank_name',
        'bank_branch',
        'routing_number',
        'swift_code',
        'terminal_provider',
        'terminal_id',
        'merchant_id',
        'mobile_provider',
        'mobile_number',
        'is_active',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function walletMaps(): HasMany
    {
        return $this->hasMany(PaymentMethodWalletMap::class)->orderBy('location_id');
    }

    /**
     * Methods that can record inbound sales payments at this branch: active, with a wallet map
     * whose wallet applies here and has an active ledger account (same rules as {@see JournalService::resolveWalletForPayment}).
     *
     * @return Collection<int, PaymentMethod>
     */
    public static function availableForSalesAtLocation(?int $locationId): Collection
    {
        if (! $locationId) {
            return new Collection;
        }

        return static::query()
            ->where('is_active', true)
            ->whereIn('id', PaymentMethodWalletMap::query()
                ->select('payment_method_id')
                ->where('location_id', $locationId)
                ->whereHas('wallet', function (Builder $q) use ($locationId): void {
                    $q->where('is_active', true)
                        ->whereAppliesToLocation($locationId)
                        ->whereHas('ledgerAccount', fn (Builder $q2) => $q2->where('is_active', true));
                }))
            ->orderBy('name')
            ->get();
    }
}
