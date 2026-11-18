<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesInventoryLocationContext;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class CustomerTrackingController extends Controller
{
    use AppliesInventoryLocationContext;

    private const DEFAULT_LAT = 23.8103;

    private const DEFAULT_LNG = 90.4125;

    public function index(Request $request): View
    {
        $this->authorize('viewAnyTracking', Customer::class);

        $filters = [
            'q' => trim((string) $request->input('q', '')),
        ];

        $customers = Customer::query()
            ->with('branch')
            ->where('is_active', true)
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($subQuery) use ($keyword): void {
                    $subQuery->where('customer_code', 'like', "%{$keyword}%")
                        ->orWhere('name', 'like', "%{$keyword}%")
                        ->orWhere('phone', 'like', "%{$keyword}%")
                        ->orWhere('phone_numbers', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%")
                        ->orWhere('address', 'like', "%{$keyword}%")
                        ->orWhere('detailed_address', 'like', "%{$keyword}%");
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('customer-tracking.index', compact('customers', 'filters'));
    }

    public function show(Customer $customer): View
    {
        $this->authorize('viewTracking', $customer);

        $customer->load('branch');

        return view('customer-tracking.show', array_merge(
            ['customer' => $customer],
            $this->trackingMapViewData($customer),
            [
                'canUpdate' => auth()->user()?->can('updateTracking', $customer) ?? false,
            ]
        ));
    }

    public function edit(Customer $customer): View
    {
        $this->authorize('updateTracking', $customer);

        $customer->load('branch');

        return view('customer-tracking.edit', array_merge(
            ['customer' => $customer],
            $this->trackingMapViewData($customer)
        ));
    }

    /**
     * @return array{mapLat: float, mapLng: float, mapZoom: int}
     */
    private function trackingMapViewData(Customer $customer): array
    {
        return [
            'mapLat' => $customer->latitude ?? self::DEFAULT_LAT,
            'mapLng' => $customer->longitude ?? self::DEFAULT_LNG,
            'mapZoom' => $customer->hasTrackingLocation() ? 16 : 12,
        ];
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('updateTracking', $customer);

        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'detailed_address' => ['nullable', 'string', 'max:5000'],
            'location_instructions' => ['nullable', 'string', 'max:5000'],
            'house_image' => ['nullable', 'image', 'max:10240'],
            'location_image' => ['nullable', 'image', 'max:10240'],
            'remove_house_image' => ['nullable', 'boolean'],
            'remove_location_image' => ['nullable', 'boolean'],
            'clear_location' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('clear_location')) {
            $customer->latitude = null;
            $customer->longitude = null;
        } else {
            $latRaw = $request->input('latitude');
            $lngRaw = $request->input('longitude');
            $hasLat = $latRaw !== null && $latRaw !== '';
            $hasLng = $lngRaw !== null && $lngRaw !== '';

            if ($hasLat xor $hasLng) {
                return back()
                    ->withErrors(['latitude' => 'Latitude and longitude must both be set or both be empty.'])
                    ->withInput();
            }

            if ($hasLat && $hasLng) {
                $customer->latitude = round((float) $latRaw, 8);
                $customer->longitude = round((float) $lngRaw, 8);
            }
        }

        $customer->detailed_address = $data['detailed_address'] ?? null;
        $customer->location_instructions = $data['location_instructions'] ?? null;

        $storageDir = 'customers/tracking/'.$customer->id;

        if ($request->boolean('remove_house_image')) {
            $this->deleteTrackingImage($customer->house_image_path);
            $customer->house_image_path = null;
        }

        if ($request->boolean('remove_location_image')) {
            $this->deleteTrackingImage($customer->location_image_path);
            $customer->location_image_path = null;
        }

        if ($request->hasFile('house_image')) {
            $this->deleteTrackingImage($customer->house_image_path);
            $customer->house_image_path = $request->file('house_image')->store($storageDir, 'public');
        }

        if ($request->hasFile('location_image')) {
            $this->deleteTrackingImage($customer->location_image_path);
            $customer->location_image_path = $request->file('location_image')->store($storageDir, 'public');
        }

        $customer->save();

        return redirect()
            ->route('customer-tracking.show', $customer)
            ->with('status', 'Customer tracking updated.');
    }

    private function deleteTrackingImage(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
