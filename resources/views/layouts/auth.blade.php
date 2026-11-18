<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Login') - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @keyframes auth-float {
            0%, 100% { transform: translateY(0) rotate(var(--auth-rot, 0deg)); }
            50% { transform: translateY(-14px) rotate(calc(var(--auth-rot, 0deg) + 3deg)); }
        }
        @keyframes auth-drift {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(8px, -10px) scale(1.02); }
            50% { transform: translate(-6px, 6px) scale(0.98); }
            75% { transform: translate(4px, 8px) scale(1.01); }
        }
        @keyframes auth-pulse-soft {
            0%, 100% { opacity: 0.38; }
            50% { opacity: 0.55; }
        }
        .auth-floating-layer {
            position: absolute;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
        }
        .auth-float-icon {
            position: absolute;
            color: rgb(59 130 246 / 0.35);
            animation: auth-float 7s ease-in-out infinite, auth-pulse-soft 5s ease-in-out infinite;
        }
        .auth-float-icon:nth-child(odd) {
            color: rgb(245 158 11 / 0.32);
            animation: auth-drift 9s ease-in-out infinite, auth-pulse-soft 6s ease-in-out infinite;
        }
        .auth-float-icon svg {
            display: block;
            width: var(--auth-icon-size, 2.5rem);
            height: var(--auth-icon-size, 2.5rem);
        }
        .auth-float-icon:nth-child(1) { top: 6%; left: 8%; --auth-rot: -6deg; animation-delay: 0s, 0.3s; --auth-icon-size: 2.75rem; }
        .auth-float-icon:nth-child(2) { top: 14%; right: 10%; --auth-rot: 4deg; animation-delay: -1s, -0.5s; --auth-icon-size: 2.25rem; }
        .auth-float-icon:nth-child(3) { top: 38%; left: 4%; --auth-rot: 8deg; animation-delay: -2s, -1s; --auth-icon-size: 3rem; }
        .auth-float-icon:nth-child(4) { top: 28%; right: 6%; --auth-rot: -4deg; animation-delay: -0.5s, -2s; --auth-icon-size: 2.5rem; }
        .auth-float-icon:nth-child(5) { bottom: 22%; left: 12%; --auth-rot: 5deg; animation-delay: -3s, 0s; --auth-icon-size: 2.6rem; }
        .auth-float-icon:nth-child(6) { bottom: 18%; right: 8%; --auth-rot: -8deg; animation-delay: -1.5s, -0.8s; --auth-icon-size: 2.4rem; }
        .auth-float-icon:nth-child(7) { top: 52%; right: 18%; --auth-rot: 3deg; animation-delay: -2.5s, -1.2s; --auth-icon-size: 2.2rem; }
        .auth-float-icon:nth-child(8) { bottom: 42%; left: 20%; --auth-rot: -3deg; animation-delay: -4s, -0.4s; --auth-icon-size: 2.35rem; }
        .auth-float-icon:nth-child(9) { top: 8%; left: 42%; --auth-rot: 6deg; animation-delay: -0.8s, -2.2s; --auth-icon-size: 2rem; }
        .auth-float-icon:nth-child(10) { bottom: 8%; left: 38%; --auth-rot: -5deg; animation-delay: -3.2s, -1s; --auth-icon-size: 2.15rem; }
        @media (prefers-reduced-motion: reduce) {
            .auth-float-icon {
                animation: none;
                opacity: 0.35;
            }
        }
    </style>
</head>
<body class="min-h-screen overflow-x-hidden bg-gradient-to-br from-slate-50 via-amber-50/80 to-sky-100/90 text-slate-900 antialiased">
    <div class="relative flex min-h-screen flex-col">
        <div class="auth-floating-layer" aria-hidden="true">
            {{-- Inventory / logistics iconography (stroke SVGs) --}}
            <div class="auth-float-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                </svg>
            </div>
            <div class="auth-float-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193l-.911-.454M11 3.375l-.894.447m0 0l-.342.171m.342-.171L7.74 4.757m3.806-.203l-.342-.171m0 0l-.342-.172M7.74 4.757l-.342.171M7.74 4.757H5.085M17.25 15l2.447-1.095a18.067 18.067 0 012.012 5.295M17.25 15l-2.445 1.094m0 0a18.083 18.083 0 01-5.255 1.213M14.805 16.094l.894-.447m0 0L19.5 19.944m-1.41-5.475l2.447 1.094m-8.016-4.586L6 18.376m4.568-2.813 2.218-1.11" />
                </svg>
            </div>
            <div class="auth-float-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 16.875h3.375m0 0h3.375m-3.375 0V13.5m0 3.375v3.375M6 10.875h18M3.375 21h17.25c.621 0 1.125-.504 1.125-1.125V4.875c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v15.75c0 .621.504 1.125 1.125 1.125z" />
                </svg>
            </div>
            <div class="auth-float-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m0 0h3.75m-3.75 0H12m8.25-5.625v4.125c0 .621-.504 1.125-1.125 1.125h-4.125M8.25 9.75h7.5" />
                </svg>
            </div>
            <div class="auth-float-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" />
                </svg>
            </div>
            <div class="auth-float-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                </svg>
            </div>
            <div class="auth-float-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664v.75h-6V4.5c0-.231.035-.454.1-.664C6.845 4.068 7.98 3 9.75 3h4.5c1.77 0 2.905 1.068 3.65 1.836.065.21.1.433.1.664v.75h-6V4.5z" />
                </svg>
            </div>
            <div class="auth-float-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            </div>
            <div class="auth-float-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
                </svg>
            </div>
            <div class="auth-float-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018 3H6a2.25 2.25 0 00-2.25 2.25v15A2.25 2.25 0 004.5 21h15a2.25 2.25 0 002.25-2.25v-15z" />
                </svg>
            </div>
        </div>

        <main class="relative z-10 flex flex-1 items-center justify-center px-4 py-16 sm:px-6 sm:py-20">
            <div class="w-full max-w-[420px]">
                <div class="rounded-2xl border border-white/60 bg-white/90 p-8 shadow-2xl shadow-slate-900/10 ring-1 ring-slate-200/80 backdrop-blur-md sm:p-10">
                    @yield('content')
                </div>
            </div>
        </main>

        <footer class="relative z-10 shrink-0 pb-6 pt-2 text-center">
            <p class="text-xs font-medium tracking-wide text-slate-500">
                Powered by <span class="text-slate-700">Sohojware</span>
            </p>
        </footer>
    </div>
</body>
</html>
