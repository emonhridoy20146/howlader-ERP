<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'ledger_account_id',
        'code',
        'name',
        'kind',
        'bank_name',
        'account_number',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'wallet_location');
    }

    /**
     * When no branches are linked, the wallet is available at every branch.
     */
    public function appliesToLocation(int $locationId): bool
    {
        if (! $this->relationLoaded('locations')) {
            return ! $this->locations()->exists()
                || $this->locations()->where('locations.id', $locationId)->exists();
        }

        return $this->locations->isEmpty()
            || $this->locations->contains('id', $locationId);
    }

    public function scopeWhereAppliesToLocation(Builder $query, int $locationId): Builder
    {
        return $query->where(function (Builder $q) use ($locationId): void {
            $q->whereDoesntHave('locations')
                ->orWhereHas('locations', fn (Builder $q2) => $q2->where('locations.id', $locationId));
        });
    }

    /**
     * Wallets the user may manage: unrestricted, or linked to at least one branch they can access.
     */
    public function scopeForUserLocations(Builder $query, User $user): Builder
    {
        $ids = $user->accessibleLocationIds();
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($ids): void {
            $q->whereDoesntHave('locations')
                ->orWhereHas('locations', fn (Builder $q2) => $q2->whereIn('locations.id', $ids));
        });
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function paymentMethodMaps(): HasMany
    {
        return $this->hasMany(PaymentMethodWalletMap::class);
    }

    public static function kinds(): array
    {
        return [
            'cash' => 'Cash',
            'bank' => 'Bank',
            'card_terminal' => 'Card terminal',
            'mobile_banking' => 'Mobile banking',
            'digital' => 'Digital wallet',
        ];
    }

    /**
     * Net asset balance for this wallet’s ledger account (debit − credit).
     */
    public function currentBalance(): float
    {
        $raw = JournalLine::query()
            ->where('ledger_account_id', $this->ledger_account_id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as bal')
            ->value('bal');

        return round((float) ($raw ?? 0), 2);
    }
}
