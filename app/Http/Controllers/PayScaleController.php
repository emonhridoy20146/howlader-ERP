<?php

namespace App\Http\Controllers;

use App\Models\PayScale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PayScaleController extends Controller
{
    public function index(Request $request): View
    {
        $filters = ['q' => trim((string) $request->input('q', ''))];
        $editId = $request->integer('edit');
        $editingPayScale = $editId ? PayScale::find($editId) : null;

        $payScales = PayScale::query()
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%')
                ->orWhere('code', 'like', '%'.$filters['q'].'%'))
            ->orderBy('name')
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $kpis = [
            'total' => PayScale::count(),
            'active' => PayScale::where('is_active', true)->count(),
            'filtered' => $payScales->total(),
        ];

        return view('hr.pay-scales.index', compact('payScales', 'editingPayScale', 'filters', 'kpis'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('hr.pay-scales.index', ['drawer' => 'create']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        PayScale::create($data);

        return redirect()->route('hr.pay-scales.index')->with('status', 'Pay scale created successfully.');
    }

    public function edit(PayScale $payScale): RedirectResponse
    {
        return redirect()->route('hr.pay-scales.index', ['drawer' => 'edit', 'edit' => $payScale->id]);
    }

    public function update(Request $request, PayScale $payScale): RedirectResponse
    {
        $payScale->update($this->validated($request, $payScale));

        return redirect()->route('hr.pay-scales.index')->with('status', 'Pay scale updated successfully.');
    }

    public function destroy(PayScale $payScale): RedirectResponse
    {
        $payScale->delete();

        return redirect()->route('hr.pay-scales.index')->with('status', 'Pay scale deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PayScale $payScale = null): array
    {
        $uniqueRule = $payScale
            ? 'unique:pay_scales,name,'.$payScale->id
            : 'unique:pay_scales,name';

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', $uniqueRule],
            'code' => ['nullable', 'string', 'max:30'],
            'basic_salary' => ['required', 'numeric', 'min:0'],
            'house_rent_allowance' => ['nullable', 'numeric', 'min:0'],
            'medical_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowance' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['house_rent_allowance'] = round((float) ($data['house_rent_allowance'] ?? 0), 2);
        $data['medical_allowance'] = round((float) ($data['medical_allowance'] ?? 0), 2);
        $data['transport_allowance'] = round((float) ($data['transport_allowance'] ?? 0), 2);
        $data['other_allowance'] = round((float) ($data['other_allowance'] ?? 0), 2);
        $data['basic_salary'] = round((float) $data['basic_salary'], 2);
        $data['gross_salary'] = PayScale::computeGross($data);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
