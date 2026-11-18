@props([
    'customer',
    'mapLat' => 23.8103,
    'mapLng' => 90.4125,
    'mapZoom' => 12,
    'formAction' => null,
    'showBackLink' => true,
    'backUrl' => null,
    'backLabel' => 'Back to search',
])

@php
    $formAction = $formAction ?? route('customer-tracking.update', $customer);
    $backUrl = $backUrl ?? route('customer-tracking.index');
@endphp

@include('customer-tracking.partials.leaflet-assets')
<script>
    window.customerTrackingEditMap = function (config) {
        return {
            lat: config.lat,
            lng: config.lng,
            defaultLat: config.defaultLat,
            defaultLng: config.defaultLng,
            mapZoom: config.mapZoom,
            map: null,
            marker: null,
            geoError: '',
            initMap() {
                if (typeof L === 'undefined') {
                    return;
                }
                const startLat = this.lat ?? config.defaultLat;
                const startLng = this.lng ?? config.defaultLng;
                const zoom = this.lat !== null && this.lng !== null ? config.mapZoom : 12;
                this.map = L.map(this.$refs.mapCanvas).setView([startLat, startLng], zoom);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors',
                }).addTo(this.map);
                if (this.lat !== null && this.lng !== null) {
                    this.setMarker(this.lat, this.lng, false);
                }
                this.map.on('click', (event) => {
                    this.setMarker(event.latlng.lat, event.latlng.lng);
                });
                this.$nextTick(() => this.map.invalidateSize());
            },
            setMarker(lat, lng, pan = true) {
                this.lat = Number(lat.toFixed(8));
                this.lng = Number(lng.toFixed(8));
                if (this.$refs.clearLocationInput) {
                    this.$refs.clearLocationInput.checked = false;
                }
                if (this.marker) {
                    this.marker.setLatLng([this.lat, this.lng]);
                } else if (this.map) {
                    this.marker = L.marker([this.lat, this.lng], { draggable: true }).addTo(this.map);
                    this.marker.on('dragend', () => {
                        const pos = this.marker.getLatLng();
                        this.lat = Number(pos.lat.toFixed(8));
                        this.lng = Number(pos.lng.toFixed(8));
                    });
                }
                if (pan && this.map) {
                    this.map.panTo([this.lat, this.lng]);
                }
            },
            useCurrentLocation() {
                this.geoError = '';
                if (! navigator.geolocation) {
                    this.geoError = 'Geolocation is not supported on this device.';
                    return;
                }
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        this.setMarker(position.coords.latitude, position.coords.longitude);
                    },
                    (error) => {
                        this.geoError = error.message || 'Could not read your current location.';
                    },
                    { enableHighAccuracy: true, timeout: 15000 }
                );
            },
            clearLocation() {
                this.lat = null;
                this.lng = null;
                if (this.marker && this.map) {
                    this.map.removeLayer(this.marker);
                    this.marker = null;
                }
                this.$refs.clearLocationInput.checked = true;
            },
        };
    };
</script>

<div
    class="space-y-4"
    x-data="customerTrackingEditMap(@js([
        'lat' => $customer->latitude,
        'lng' => $customer->longitude,
        'defaultLat' => $mapLat,
        'defaultLng' => $mapLng,
        'mapZoom' => $mapZoom,
    ]))"
    x-init="$nextTick(() => initMap())"
>
    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Edit tracking</p>
                <h2 class="mt-1 text-xl font-semibold text-slate-900">{{ $customer->name }}</h2>
                <p class="mt-1 text-sm text-slate-600">
                    <span class="font-mono text-slate-900">{{ $customer->customer_code }}</span>
                    · {{ $customer->phone ?: 'No phone' }}
                </p>
                @if ($customer->address)
                    <p class="mt-2 text-sm text-slate-600">Registered address: {{ $customer->address }}</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($showBackLink)
                    <a href="{{ $backUrl }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100">{{ $backLabel }}</a>
                @endif
                <a href="{{ route('customer-tracking.show', $customer) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100">View tracking</a>
            </div>
        </div>
    </section>

    <form method="post" action="{{ $formAction }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        @method('PUT')
        <input type="hidden" name="clear_location" value="0" x-ref="clearLocationInput">

        @include('customer-tracking.partials.form-fields')

        <div class="flex flex-wrap gap-2">
            <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">Save tracking</button>
            <button type="button" @click="clearLocation()" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-medium text-rose-800 hover:bg-rose-100">Clear map pin</button>
            <a href="{{ route('customer-tracking.show', $customer) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Cancel</a>
        </div>
    </form>
</div>
