@props([
    'customer',
    'mapLat' => 23.8103,
    'mapLng' => 90.4125,
    'mapZoom' => 12,
    'mapHeightClass' => 'h-[24rem] md:h-[28rem]',
])

@once
    @include('customer-tracking.partials.leaflet-assets')
    <script>
        window.customerTrackingReadonlyMap = function (config) {
            return {
                lat: config.lat,
                lng: config.lng,
                hasPin: config.hasPin,
                customerName: config.customerName,
                popupAddress: config.popupAddress,
                defaultLat: config.defaultLat,
                defaultLng: config.defaultLng,
                mapZoom: config.mapZoom,
                map: null,
                marker: null,
                userMarker: null,
                distanceKm: null,
                geoError: '',
                isFullscreen: false,
                initMap() {
                    if (typeof L === 'undefined') {
                        return;
                    }
                    const startLat = this.hasPin ? this.lat : this.defaultLat;
                    const startLng = this.hasPin ? this.lng : this.defaultLng;
                    const zoom = this.hasPin ? this.mapZoom : 12;
                    this.map = L.map(this.$refs.mapCanvas, {
                        scrollWheelZoom: true,
                        dragging: true,
                        touchZoom: true,
                        zoomControl: true,
                    }).setView([startLat, startLng], zoom);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; OpenStreetMap contributors',
                    }).addTo(this.map);
                    if (this.hasPin) {
                        this.placeCustomerMarker();
                    }
                    setTimeout(() => this.map?.invalidateSize(), 120);
                    window.addEventListener('resize', () => this.map?.invalidateSize());
                },
                placeCustomerMarker() {
                    if (! this.map || this.lat === null || this.lng === null) {
                        return;
                    }
                    if (this.marker) {
                        this.map.removeLayer(this.marker);
                    }
                    this.marker = L.marker([this.lat, this.lng]).addTo(this.map);
                    let popupHtml = '<div class="tracking-map-popup"><strong>' + this.customerName + '</strong>';
                    if (this.popupAddress) {
                        popupHtml += '<br><span>' + this.popupAddress + '</span>';
                    }
                    popupHtml += '</div>';
                    this.marker.bindPopup(popupHtml).openPopup();
                },
                recenterCustomer() {
                    if (! this.map || ! this.hasPin) {
                        return;
                    }
                    this.map.setView([this.lat, this.lng], this.mapZoom);
                    this.marker?.openPopup();
                },
                showMyLocation() {
                    this.geoError = '';
                    if (! navigator.geolocation) {
                        this.geoError = 'Geolocation is not supported on this device.';
                        return;
                    }
                    navigator.geolocation.getCurrentPosition(
                        (position) => {
                            const userLat = position.coords.latitude;
                            const userLng = position.coords.longitude;
                            if (this.userMarker) {
                                this.map.removeLayer(this.userMarker);
                            }
                            this.userMarker = L.circleMarker([userLat, userLng], {
                                radius: 8,
                                color: '#0284c7',
                                fillColor: '#0ea5e9',
                                fillOpacity: 0.9,
                                weight: 2,
                            }).addTo(this.map).bindPopup('You are here');
                            if (this.hasPin) {
                                this.distanceKm = this.haversineKm(userLat, userLng, this.lat, this.lng);
                                const bounds = L.latLngBounds([userLat, userLng], [this.lat, this.lng]);
                                this.map.fitBounds(bounds.pad(0.25));
                            } else {
                                this.map.setView([userLat, userLng], 15);
                            }
                        },
                        (error) => {
                            this.geoError = error.message || 'Could not read your current location.';
                        },
                        { enableHighAccuracy: true, timeout: 15000 }
                    );
                },
                haversineKm(lat1, lon1, lat2, lon2) {
                    const toRad = (value) => value * Math.PI / 180;
                    const dLat = toRad(lat2 - lat1);
                    const dLon = toRad(lon2 - lon1);
                    const a = Math.sin(dLat / 2) ** 2
                        + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
                    return 6371 * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
                },
                formatDistance(km) {
                    if (km === null) {
                        return '';
                    }
                    if (km < 1) {
                        return Math.round(km * 1000) + ' m away';
                    }
                    return km.toFixed(1) + ' km away';
                },
                async toggleFullscreen() {
                    const shell = this.$refs.mapShell;
                    if (! document.fullscreenElement) {
                        await shell.requestFullscreen?.();
                        this.isFullscreen = true;
                    } else {
                        await document.exitFullscreen?.();
                        this.isFullscreen = false;
                    }
                    setTimeout(() => this.map?.invalidateSize(), 150);
                },
            };
        };
    </script>
@endonce

<div
    class="tracking-map-shell relative"
    x-data="customerTrackingReadonlyMap(@js([
        'lat' => $customer->latitude,
        'lng' => $customer->longitude,
        'hasPin' => $customer->hasTrackingLocation(),
        'customerName' => $customer->name,
        'popupAddress' => trim((string) ($customer->detailed_address ?: $customer->address)),
        'defaultLat' => $mapLat,
        'defaultLng' => $mapLng,
        'mapZoom' => $mapZoom,
    ]))"
    x-init="$nextTick(() => initMap())"
    x-ref="mapShell"
    @fullscreenchange.window="isFullscreen = !!document.fullscreenElement; $nextTick(() => map?.invalidateSize())"
>
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-4 py-3">
        <div>
            <h3 class="text-sm font-semibold text-slate-900">Map view</h3>
            @if ($customer->hasTrackingLocation())
                <p class="mt-0.5 font-mono text-xs text-slate-500">{{ $customer->coordinatesLabel() }}</p>
            @else
                <p class="mt-0.5 text-xs text-amber-700">No GPS pin saved — showing default area</p>
            @endif
            <p x-show="distanceKm !== null" x-text="formatDistance(distanceKm)" class="mt-1 text-xs font-medium text-sky-700"></p>
            <p x-show="geoError" x-text="geoError" class="mt-1 text-xs text-rose-600"></p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($customer->hasTrackingLocation())
                <button type="button" @click="recenterCustomer()" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-100">Recenter pin</button>
            @endif
            <button type="button" @click="showMyLocation()" class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-1.5 text-xs font-medium text-sky-900 hover:bg-sky-100">My location</button>
            <button type="button" @click="toggleFullscreen()" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-100">
                <span x-text="isFullscreen ? 'Exit full screen' : 'Full screen'"></span>
            </button>
            @if ($customer->googleMapsDirectionsUrl())
                <a href="{{ $customer->googleMapsDirectionsUrl() }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-900 hover:bg-emerald-100">Get directions</a>
            @endif
        </div>
    </div>

    <div class="relative">
        <div x-ref="mapCanvas" class="tracking-map-canvas {{ $mapHeightClass }} w-full"></div>
        @if (! $customer->hasTrackingLocation())
            <div class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-slate-900/70 to-transparent p-4 pt-10">
                <p class="text-sm font-medium text-white">Add a map pin to enable navigation and distance.</p>
            </div>
        @endif
    </div>
</div>
