<section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h3 class="text-sm font-semibold text-slate-900">Map location</h3>
            <p class="text-xs text-slate-500">Click the map, drag the pin, or use your current GPS location.</p>
        </div>
        <button type="button" @click="useCurrentLocation()" class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-1.5 text-xs font-medium text-sky-900 hover:bg-sky-100">
            Use my current location
        </button>
    </div>
    <p x-show="geoError" x-text="geoError" class="mb-2 text-sm text-rose-700"></p>
    <div class="tracking-map-shell overflow-hidden rounded-xl ring-1 ring-slate-200">
        <div x-ref="mapCanvas" class="tracking-map-canvas h-80 w-full"></div>
    </div>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Latitude</label>
            <input type="number" step="any" name="latitude" x-model="lat" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="e.g. 23.81030000">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Longitude</label>
            <input type="number" step="any" name="longitude" x-model="lng" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="e.g. 90.41250000">
        </div>
    </div>
</section>

<section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
    <h3 class="text-sm font-semibold text-slate-900">Detailed address</h3>
    <p class="mt-1 text-xs text-slate-500">House number, area, landmarks — separate from the customer’s general address.</p>
    <textarea name="detailed_address" rows="3" class="mt-3 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('detailed_address', $customer->detailed_address) }}</textarea>
</section>

<section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
    <h3 class="text-sm font-semibold text-slate-900">Finding instructions</h3>
    <p class="mt-1 text-xs text-slate-500">Directions for delivery or collection staff.</p>
    <textarea name="location_instructions" rows="3" class="mt-3 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('location_instructions', $customer->location_instructions) }}</textarea>
</section>

<section class="grid gap-4 lg:grid-cols-2">
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h3 class="text-sm font-semibold text-slate-900">House image</h3>
        <p class="mt-1 text-xs text-slate-500">Photo of the customer’s house or building.</p>
        @if ($customer->houseImageUrl())
            <img src="{{ $customer->houseImageUrl() }}" alt="House photo for {{ $customer->name }}" class="mt-3 max-h-48 w-full rounded-xl object-cover ring-1 ring-slate-200">
            <label class="mt-3 flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="remove_house_image" value="1" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                Remove current house image
            </label>
        @endif
        <input type="file" name="house_image" accept="image/*" class="mt-3 block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-800 hover:file:bg-slate-200">
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h3 class="text-sm font-semibold text-slate-900">Location image</h3>
        <p class="mt-1 text-xs text-slate-500">Photo of the gate, road, or nearby landmark.</p>
        @if ($customer->locationImageUrl())
            <img src="{{ $customer->locationImageUrl() }}" alt="Location photo for {{ $customer->name }}" class="mt-3 max-h-48 w-full rounded-xl object-cover ring-1 ring-slate-200">
            <label class="mt-3 flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="remove_location_image" value="1" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                Remove current location image
            </label>
        @endif
        <input type="file" name="location_image" accept="image/*" class="mt-3 block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-800 hover:file:bg-slate-200">
    </div>
</section>
