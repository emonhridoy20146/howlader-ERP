<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class CompanyPadSettingsController extends Controller
{
    /**
     * @return list<string>
     */
    private function keys(): array
    {
        return [
            'company_pad_name',
            'company_pad_logo_path',
            'company_pad_tagline',
            'company_pad_address',
            'company_pad_phone',
            'company_pad_email',
            'company_pad_website',
            'company_pad_header_note',
            'company_pad_footer_note',
            'company_pad_footer_left_label',
            'company_pad_footer_right_label',
            'global_maximum_deductible',
        ];
    }

    public function edit(): View
    {
        $settings = AppSetting::getMany($this->keys());

        $defaults = [
            'company_pad_name' => config('app.name'),
            'company_pad_logo_path' => '',
            'company_pad_tagline' => 'Sales & Inventory Management',
            'company_pad_address' => '',
            'company_pad_phone' => '',
            'company_pad_email' => '',
            'company_pad_website' => '',
            'company_pad_header_note' => '',
            'company_pad_footer_note' => 'Thank you for your business.',
            'company_pad_footer_left_label' => 'Customer Signature',
            'company_pad_footer_right_label' => 'Authorized Signature',
            'global_maximum_deductible' => '',
        ];

        foreach ($defaults as $key => $value) {
            if ($settings[$key] === null || $settings[$key] === '') {
                $settings[$key] = $value;
            }
        }

        return view('settings.company-pad', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_pad_name' => ['required', 'string', 'max:120'],
            'company_pad_logo' => ['nullable', 'image', 'max:2048'],
            'company_pad_logo_remove' => ['nullable', 'boolean'],
            'company_pad_tagline' => ['nullable', 'string', 'max:180'],
            'company_pad_address' => ['nullable', 'string', 'max:500'],
            'company_pad_phone' => ['nullable', 'string', 'max:80'],
            'company_pad_email' => ['nullable', 'string', 'max:120'],
            'company_pad_website' => ['nullable', 'string', 'max:150'],
            'company_pad_header_note' => ['nullable', 'string', 'max:300'],
            'company_pad_footer_note' => ['nullable', 'string', 'max:300'],
            'company_pad_footer_left_label' => ['nullable', 'string', 'max:80'],
            'company_pad_footer_right_label' => ['nullable', 'string', 'max:80'],
            'global_maximum_deductible' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $current = AppSetting::getMany(['company_pad_logo_path']);
        $logoPath = $current['company_pad_logo_path'] ?: null;

        if ($request->boolean('company_pad_logo_remove') && $logoPath) {
            Storage::disk('public')->delete($logoPath);
            $logoPath = null;
        }

        if ($request->hasFile('company_pad_logo')) {
            if ($logoPath) {
                Storage::disk('public')->delete($logoPath);
            }

            $logoPath = $request->file('company_pad_logo')->store('company-pad', 'public');
        }

        AppSetting::setMany([
            'company_pad_name' => $data['company_pad_name'],
            'company_pad_logo_path' => $logoPath,
            'company_pad_tagline' => $data['company_pad_tagline'] ?? null,
            'company_pad_address' => $data['company_pad_address'] ?? null,
            'company_pad_phone' => $data['company_pad_phone'] ?? null,
            'company_pad_email' => $data['company_pad_email'] ?? null,
            'company_pad_website' => $data['company_pad_website'] ?? null,
            'company_pad_header_note' => $data['company_pad_header_note'] ?? null,
            'company_pad_footer_note' => $data['company_pad_footer_note'] ?? null,
            'company_pad_footer_left_label' => $data['company_pad_footer_left_label'] ?? null,
            'company_pad_footer_right_label' => $data['company_pad_footer_right_label'] ?? null,
            'global_maximum_deductible' => isset($data['global_maximum_deductible']) && $data['global_maximum_deductible'] !== ''
                ? (string) $data['global_maximum_deductible']
                : null,
        ]);

        return back()->with('status', 'Company pad settings updated.');
    }
}
