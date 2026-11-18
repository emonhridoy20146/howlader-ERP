<?php

namespace App\Support;

use App\Models\AppSetting;

final class CompanyPad
{
    /**
     * @return array<string, string>
     */
    public static function settings(): array
    {
        $keys = [
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
        ];

        $stored = AppSetting::getMany($keys);

        return [
            'company_pad_name' => (string) ($stored['company_pad_name'] ?? config('app.name')),
            'company_pad_logo_path' => (string) ($stored['company_pad_logo_path'] ?? ''),
            'company_pad_tagline' => (string) ($stored['company_pad_tagline'] ?? 'Sales & Inventory Management'),
            'company_pad_address' => (string) ($stored['company_pad_address'] ?? ''),
            'company_pad_phone' => (string) ($stored['company_pad_phone'] ?? ''),
            'company_pad_email' => (string) ($stored['company_pad_email'] ?? ''),
            'company_pad_website' => (string) ($stored['company_pad_website'] ?? ''),
            'company_pad_header_note' => (string) ($stored['company_pad_header_note'] ?? ''),
            'company_pad_footer_note' => (string) ($stored['company_pad_footer_note'] ?? 'Thank you for your business.'),
            'company_pad_footer_left_label' => (string) ($stored['company_pad_footer_left_label'] ?? 'Customer Signature'),
            'company_pad_footer_right_label' => (string) ($stored['company_pad_footer_right_label'] ?? 'Authorized Signature'),
        ];
    }
}
