@props([
    'label',
])

<div {{ $attributes->merge(['class' => 'group relative overflow-hidden rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:ring-slate-300']) }}>
    <!-- Decorative background elements -->
    <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-gradient-to-br from-emerald-50 to-teal-100/50 blur-2xl transition-all duration-500 group-hover:scale-150 group-hover:bg-emerald-100/50"></div>
    <div class="absolute -bottom-8 -left-8 h-32 w-32 rounded-full bg-gradient-to-tr from-sky-50 to-indigo-100/30 blur-2xl transition-all duration-500 group-hover:scale-150"></div>

    <div class="relative z-10 flex flex-col h-full justify-between">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-500/80">{{ $label }}</p>
        <p class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">
            {{ $slot }}
        </p>
    </div>
</div>
