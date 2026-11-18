<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'dizicashier_uuid',
        'name',
        'contact_person',
        'phone',
        'phone_numbers',
        'email',
        'address',
        'tax_number',
        'outstanding_balance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'phone_numbers' => 'array',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function normalizedPhoneNumbers(): array
    {
        $numbers = collect($this->phone_numbers ?? [])
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values()
            ->all();

        if ($numbers === [] && $this->phone) {
            $numbers = [trim((string) $this->phone)];
        }

        return $numbers;
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
