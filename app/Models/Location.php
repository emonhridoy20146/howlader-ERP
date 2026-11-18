<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'dizicashier_uuid',
        'code',
        'name',
        'type',
        'address',
        'phone',
        'manager_name',
        'manager_user_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function resolvedPaymentMethods(): array
    {
        $configuredCollection = $this->relationLoaded('paymentMethods')
            ? $this->paymentMethods
                ->where('is_active', true)
                ->sortBy('name')
                ->pluck('name')
            : $this->paymentMethods()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name');

        $configured = $configuredCollection
            ->map(fn ($name) => (string) $name)
            ->filter(fn ($name) => $name !== '')
            ->values()
            ->all();

        static $globalMethods = null;
        if ($globalMethods === null) {
            $globalMethods = DB::table('payment_methods')
                ->whereNull('location_id')
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name')
                ->map(fn ($name) => (string) $name)
                ->values()
                ->all();
        }

        return $configured !== []
            ? $configured
            : $globalMethods;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'branch_id');
    }

    public function managerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function accessibleByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function wallets(): BelongsToMany
    {
        return $this->belongsToMany(Wallet::class, 'wallet_location');
    }

    public function annualTargets(): HasMany
    {
        return $this->hasMany(BranchAnnualTarget::class);
    }

    public function monthlyTargets(): HasMany
    {
        return $this->hasMany(BranchMonthlyTarget::class);
    }

    public function scopeWarehouses(Builder $query): Builder
    {
        return $query->where('type', 'warehouse');
    }

    public function scopeBranches(Builder $query): Builder
    {
        return $query->where('type', 'branch');
    }

    /** Branches or warehouses — both may hold billable inventory for sales invoices. */
    public function scopeBranchesAndWarehouses(Builder $query): Builder
    {
        return $query->whereIn('type', ['branch', 'warehouse']);
    }
}
