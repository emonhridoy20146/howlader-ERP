<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'dizicashier_uuid',
        'customer_code',
        'name',
        'phone',
        'branch_id',
        'default_guarantor_id',
        'photo_path',
        'nid_path',
        'attachments',
        'phone_numbers',
        'email',
        'address',
        'latitude',
        'longitude',
        'detailed_address',
        'location_instructions',
        'house_image_path',
        'location_image_path',
        'id_type',
        'id_number',
        'credit_limit',
        'outstanding_balance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'phone_numbers' => 'array',
            'attachments' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'branch_id');
    }

    public function riskSnapshot(): HasOne
    {
        return $this->hasOne(CustomerRiskSnapshot::class);
    }

    public function defaultGuarantor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'default_guarantor_id');
    }

    public function applyDefaultGuarantorToInvoice(SalesInvoice $invoice, ?string $notes = null): void
    {
        if ((int) $invoice->customer_id !== (int) $this->id) {
            throw new \InvalidArgumentException('Invoice does not belong to this customer.');
        }

        if ($invoice->sale_type !== 'installment') {
            throw new \InvalidArgumentException('Guarantor can only be attached to installment invoices.');
        }

        $guarantor = $this->defaultGuarantor;
        if (! $guarantor instanceof self) {
            throw new \InvalidArgumentException('Customer has no default guarantor configured.');
        }

        self::copyGuarantorToInvoice($guarantor, $invoice, $notes ?? $invoice->guarantor_notes);
    }

    public static function copyGuarantorToInvoice(self $guarantor, SalesInvoice $invoice, ?string $notes = null, ?string $nidNumber = null, ?string $address = null): void
    {
        $invoice->update([
            'guarantor_id'            => $guarantor->id,
            'guarantor_name'          => $guarantor->name,
            'guarantor_address'       => $address ?? $guarantor->address,
            'guarantor_phone_numbers' => $guarantor->normalizedPhoneNumbers(),
            'guarantor_notes'         => $notes,
            'guarantor_nid_number'    => $nidNumber ?? $guarantor->id_number,
        ]);
    }

    public function photoUrl(): ?string
    {
        if ($this->photo_path === null || $this->photo_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->photo_path);
    }

    public function nidUrl(): ?string
    {
        if ($this->nid_path === null || $this->nid_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->nid_path);
    }

    public function normalizedPhoneNumbers(): array
    {
        $phones = array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            is_array($this->phone_numbers) ? $this->phone_numbers : []
        ), static fn (string $value): bool => $value !== '');

        if ($this->phone !== null && trim((string) $this->phone) !== '') {
            array_unshift($phones, trim((string) $this->phone));
        }

        return array_values(array_unique($phones));
    }

    public function hasTrackingLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function houseImageUrl(): ?string
    {
        return $this->trackingImageUrl($this->house_image_path);
    }

    public function locationImageUrl(): ?string
    {
        return $this->trackingImageUrl($this->location_image_path);
    }

    public function googleMapsUrl(): ?string
    {
        if (! $this->hasTrackingLocation()) {
            return null;
        }

        return 'https://www.google.com/maps?q='.urlencode((string) $this->latitude.','.(string) $this->longitude);
    }

    public function googleMapsDirectionsUrl(): ?string
    {
        if (! $this->hasTrackingLocation()) {
            return null;
        }

        return 'https://www.google.com/maps/dir/?api=1&destination='.urlencode((string) $this->latitude.','.(string) $this->longitude);
    }

    public function openStreetMapUrl(): ?string
    {
        if (! $this->hasTrackingLocation()) {
            return null;
        }

        $lat = (string) $this->latitude;
        $lng = (string) $this->longitude;

        return "https://www.openstreetmap.org/?mlat={$lat}&mlon={$lng}#map=17/{$lat}/{$lng}";
    }

    public function whatsAppUrl(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->phone);
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '88'.substr($digits, 1);
        }

        return 'https://wa.me/'.$digits;
    }

    public function coordinatesLabel(): ?string
    {
        if (! $this->hasTrackingLocation()) {
            return null;
        }

        return number_format((float) $this->latitude, 6).', '.number_format((float) $this->longitude, 6);
    }

    /**
     * Text block staff can paste into chat or SMS with directions.
     */
    public function trackingShareText(): string
    {
        $lines = array_filter([
            $this->name,
            $this->customer_code !== '' ? 'Code: '.$this->customer_code : null,
            $this->phone ? 'Phone: '.$this->phone : null,
            $this->detailed_address ? 'Address: '.$this->detailed_address : ($this->address ? 'Address: '.$this->address : null),
            $this->location_instructions ? 'Directions: '.$this->location_instructions : null,
            $this->coordinatesLabel() ? 'GPS: '.$this->coordinatesLabel() : null,
            $this->googleMapsUrl(),
        ], static fn (?string $line): bool => $line !== null && trim($line) !== '');

        return implode("\n", $lines);
    }

    private function trackingImageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
