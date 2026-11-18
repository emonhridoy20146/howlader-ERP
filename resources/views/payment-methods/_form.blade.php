@csrf
@include('payment-methods._form-fields', ['method' => $method, 'locations' => $locations, 'types' => $types])
<div class="mt-8 flex flex-wrap gap-3">
    <button type="submit" class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800">Save payment method</button>
    <a href="{{ route('payment-methods.index') }}" class="rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</a>
</div>
