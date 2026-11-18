@extends('layouts.app')
@section('title', 'Company Pad Settings')
@section('page-title', 'Company Pad Settings')
@section('content')
<div class="mx-auto max-w-4xl space-y-6">
    @if (session('status'))
        <div class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800 shadow-sm">
            <svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            <p class="text-sm font-medium">{{ session('status') }}</p>
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-rose-800 shadow-sm">
            <div class="flex items-center gap-3 mb-2">
                <svg class="h-5 w-5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                <p class="text-sm font-bold">Please correct the errors below</p>
            </div>
            <ul class="list-inside list-disc pl-8 space-y-1 text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mb-8">
        <p class="text-sm text-slate-500">Configure how your company identity appears on printed invoices and official documents. These settings control the header, footer, and branding of the company pad.</p>
    </div>

    <form method="post" action="{{ route('settings.company-pad.update') }}" enctype="multipart/form-data" class="space-y-8">
        @csrf
        @method('put')

        <!-- Branding & Logo -->
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
            <div class="border-b border-slate-100 bg-slate-50/80 px-6 py-4 flex items-center gap-3">
                <div class="rounded-lg bg-indigo-100 p-2 text-indigo-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                </div>
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Brand Logo</h2>
                    <p class="text-xs text-slate-500">Used as the primary graphic on documents.</p>
                </div>
            </div>
            <div class="p-6 sm:p-8">
                <div class="flex flex-col sm:flex-row sm:items-start gap-8">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-slate-700">Upload New Logo</label>
                        <div class="mt-2 flex justify-center rounded-xl border border-dashed border-slate-300 px-6 py-8 transition hover:border-indigo-400 hover:bg-indigo-50/50">
                            <div class="text-center">
                                <svg class="mx-auto h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M1.5 6a2.25 2.25 0 012.25-2.25h16.5A2.25 2.25 0 0122.5 6v12a2.25 2.25 0 01-2.25 2.25H3.75A2.25 2.25 0 011.5 18V6zM3 16.06V18c0 .414.336.75.75.75h16.5A.75.75 0 0021 18v-1.94l-2.69-2.689a1.5 1.5 0 00-2.12 0l-.88.879.97.97a.75.75 0 11-1.06 1.06l-5.16-5.159a1.5 1.5 0 00-2.12 0L3 16.061zm10.125-7.81a1.125 1.125 0 112.25 0 1.125 1.125 0 01-2.25 0z" clip-rule="evenodd" /></svg>
                                <div class="mt-4 flex text-sm leading-6 text-slate-600">
                                    <label for="company_pad_logo" class="relative cursor-pointer rounded-md bg-white font-semibold text-indigo-600 focus-within:outline-none focus-within:ring-2 focus-within:ring-indigo-600 focus-within:ring-offset-2 hover:text-indigo-500">
                                        <span>Upload a file</span>
                                        <input id="company_pad_logo" name="company_pad_logo" type="file" class="sr-only" accept="image/png, image/jpeg, image/svg+xml">
                                    </label>
                                    <p class="pl-1">or drag and drop</p>
                                </div>
                                <p class="text-xs leading-5 text-slate-500">PNG, JPG, GIF up to 2MB</p>
                            </div>
                        </div>
                    </div>

                    @if (!empty($settings['company_pad_logo_path']))
                        <div class="sm:w-64 shrink-0 rounded-xl bg-slate-50 p-4 border border-slate-100 flex flex-col items-center justify-center text-center">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-4">Current Logo</p>
                            <img src="{{ asset('storage/'.$settings['company_pad_logo_path']) }}" alt="Company logo" class="h-24 w-auto object-contain drop-shadow-sm">
                            <label class="mt-6 inline-flex cursor-pointer items-center gap-2 rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-100">
                                <input type="checkbox" name="company_pad_logo_remove" value="1" class="rounded border-rose-300 text-rose-600 focus:ring-rose-500">
                                Remove Logo
                            </label>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Basic Information -->
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
            <div class="border-b border-slate-100 bg-slate-50/80 px-6 py-4 flex items-center gap-3">
                <div class="rounded-lg bg-sky-100 p-2 text-sky-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" /></svg>
                </div>
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Company Identity</h2>
                    <p class="text-xs text-slate-500">Official name and contact information.</p>
                </div>
            </div>
            <div class="p-6 sm:p-8 grid gap-6 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Company Name <span class="text-rose-500">*</span></label>
                    <input type="text" name="company_pad_name" value="{{ old('company_pad_name', $settings['company_pad_name']) }}" class="block w-full rounded-xl border-slate-300 py-2.5 px-4 text-sm shadow-sm transition focus:border-sky-500 focus:ring-sky-500" required placeholder="e.g. Acme Corporation Ltd.">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Tagline / Slogan</label>
                    <input type="text" name="company_pad_tagline" value="{{ old('company_pad_tagline', $settings['company_pad_tagline']) }}" class="block w-full rounded-xl border-slate-300 py-2.5 px-4 text-sm shadow-sm transition focus:border-sky-500 focus:ring-sky-500" placeholder="e.g. Empowering Your Business">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Primary Phone</label>
                    <div class="relative rounded-md shadow-sm">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-2.896-1.596-5.069-3.769-6.664-6.664l1.292-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>
                        </div>
                        <input type="text" name="company_pad_phone" value="{{ old('company_pad_phone', $settings['company_pad_phone']) }}" class="block w-full rounded-xl border-slate-300 py-2.5 pl-10 pr-4 text-sm transition focus:border-sky-500 focus:ring-sky-500" placeholder="+1 (555) 123-4567">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Email Address</label>
                    <div class="relative rounded-md shadow-sm">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                        </div>
                        <input type="email" name="company_pad_email" value="{{ old('company_pad_email', $settings['company_pad_email']) }}" class="block w-full rounded-xl border-slate-300 py-2.5 pl-10 pr-4 text-sm transition focus:border-sky-500 focus:ring-sky-500" placeholder="contact@company.com">
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Website</label>
                    <div class="relative rounded-md shadow-sm">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <span class="text-slate-400 sm:text-sm">https://</span>
                        </div>
                        <input type="text" name="company_pad_website" value="{{ old('company_pad_website', $settings['company_pad_website']) }}" class="block w-full rounded-xl border-slate-300 py-2.5 pl-16 pr-4 text-sm transition focus:border-sky-500 focus:ring-sky-500" placeholder="www.company.com">
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Full Address</label>
                    <textarea name="company_pad_address" rows="3" class="block w-full rounded-xl border-slate-300 py-2.5 px-4 text-sm shadow-sm transition focus:border-sky-500 focus:ring-sky-500" placeholder="123 Business Road, Suite 100&#10;City, State, Zip Code">{{ old('company_pad_address', $settings['company_pad_address']) }}</textarea>
                </div>
            </div>
        </div>

        <!-- Document Notes & Signatures -->
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
            <div class="border-b border-slate-100 bg-slate-50/80 px-6 py-4 flex items-center gap-3">
                <div class="rounded-lg bg-emerald-100 p-2 text-emerald-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                </div>
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Document Layout</h2>
                    <p class="text-xs text-slate-500">Notes and signature areas on invoices.</p>
                </div>
            </div>
            <div class="p-6 sm:p-8 grid gap-6 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Header Note</label>
                    <input type="text" name="company_pad_header_note" value="{{ old('company_pad_header_note', $settings['company_pad_header_note']) }}" class="block w-full rounded-xl border-slate-300 py-2.5 px-4 text-sm shadow-sm transition focus:border-emerald-500 focus:ring-emerald-500" placeholder="e.g. Sales Tax ID: 123456789">
                    <p class="mt-1 text-xs text-slate-500">Appears just below the company identity header.</p>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Footer Note</label>
                    <textarea name="company_pad_footer_note" rows="2" class="block w-full rounded-xl border-slate-300 py-2.5 px-4 text-sm shadow-sm transition focus:border-emerald-500 focus:ring-emerald-500" placeholder="e.g. Thank you for your business! Goods once sold cannot be returned.">{{ old('company_pad_footer_note', $settings['company_pad_footer_note']) }}</textarea>
                    <p class="mt-1 text-xs text-slate-500">Prints at the absolute bottom of the document.</p>
                </div>

                <div class="mt-4 md:col-span-2">
                    <div class="relative">
                        <div class="absolute inset-0 flex items-center" aria-hidden="true">
                            <div class="w-full border-t border-slate-200"></div>
                        </div>
                        <div class="relative flex justify-center">
                            <span class="bg-white px-3 text-xs font-semibold uppercase tracking-widest text-slate-500">Signatures</span>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Left Signature Label</label>
                    <input type="text" name="company_pad_footer_left_label" value="{{ old('company_pad_footer_left_label', $settings['company_pad_footer_left_label']) }}" class="block w-full rounded-xl border-slate-300 py-2.5 px-4 text-sm shadow-sm transition focus:border-emerald-500 focus:ring-emerald-500" placeholder="e.g. Customer Signature">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Right Signature Label</label>
                    <input type="text" name="company_pad_footer_right_label" value="{{ old('company_pad_footer_right_label', $settings['company_pad_footer_right_label']) }}" class="block w-full rounded-xl border-slate-300 py-2.5 px-4 text-sm shadow-sm transition focus:border-emerald-500 focus:ring-emerald-500" placeholder="e.g. Authorized Signatory">
                </div>
            </div>
        </div>

        <!-- Default max line discount (sales) -->
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
            <div class="border-b border-slate-100 bg-slate-50/80 px-6 py-4 flex items-center gap-3">
                <div class="rounded-lg bg-amber-100 p-2 text-amber-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-9l3 3 3-3" /></svg>
                </div>
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Sales · line discount cap</h2>
                    <p class="text-xs text-slate-500">Default maximum discount per invoice line (percentage of line amount). Products can set their own cap; leave blank for no default cap.</p>
                </div>
            </div>
            <div class="p-6 sm:p-8">
                <label class="block text-sm font-semibold text-slate-700 mb-1.5">Global max discount (%)</label>
                <input type="number" step="0.01" min="0" max="100" name="global_maximum_deductible" value="{{ old('global_maximum_deductible', $settings['global_maximum_deductible'] ?? '') }}" class="block max-w-xs rounded-xl border-slate-300 py-2.5 px-4 text-sm shadow-sm transition focus:border-amber-500 focus:ring-amber-500" placeholder="e.g. 10 (optional)">
                @error('global_maximum_deductible')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
                <p class="mt-2 text-xs text-slate-500">Users with “override maximum discount” permission can exceed this and per-product limits.</p>
            </div>
        </div>

        <!-- Submit Action -->
        <div class="flex items-center justify-end gap-4 border-t border-slate-200 pt-6">
            <button type="button" class="text-sm font-semibold text-slate-600 hover:text-slate-900 transition">Discard changes</button>
            <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-slate-800 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" /></svg>
                Save All Settings
            </button>
        </div>
    </form>
</div>
@endsection
