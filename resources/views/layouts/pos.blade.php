<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>POS Pro — {{ $title ?? 'Sales' }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|cairo:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased text-gray-900 bg-slate-100 h-screen overflow-hidden">
        @php
            $user = auth()->user();
            $shift = $user ? \App\Models\Shift::where('user_id', $user->id)->where('status', 'open')->latest('opened_at')->first() : null;
            $rate = (int) \App\Models\Setting::get('exchange_rate', 90000);
        @endphp

        <div class="flex flex-col h-screen">
            {{-- Slim top strip --}}
            <header class="bg-brand-700 text-white px-4 py-2 flex items-center gap-4 flex-shrink-0 shadow-card">
                <div class="flex items-center gap-2 font-semibold">
                    <div class="w-7 h-7 rounded bg-accent-light text-brand-900 flex items-center justify-center font-bold text-sm">P</div>
                    <span class="text-sm">POS Pro</span>
                </div>

                <div class="hidden md:flex items-center gap-3 ml-4 text-xs">
                    <span class="px-2 py-1 bg-brand-600 rounded">
                        Rate: 1 USD = <strong>{{ number_format($rate) }}</strong> LBP
                    </span>
                    @if ($shift)
                        <span class="px-2 py-1 bg-success/20 text-green-100 rounded">
                            ● Shift open since {{ $shift->opened_at->format('H:i') }}
                        </span>
                    @endif
                </div>

                <div class="ml-auto flex items-center gap-2 text-xs">
                    <a href="{{ route('shifts.close') }}"
                       class="px-3 py-1.5 bg-danger hover:bg-red-700 rounded transition">
                       Close Shift
                    </a>

                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" class="flex items-center gap-1 px-2 py-1 hover:bg-brand-600 rounded">
                            <div class="w-6 h-6 rounded-full bg-accent text-white flex items-center justify-center text-xs font-bold">
                                {{ strtoupper(substr($user?->name ?? '?', 0, 1)) }}
                            </div>
                            <span class="hidden md:inline">{{ $user?->name }}</span>
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        </button>

                        <div x-show="open" @click.outside="open = false" x-cloak
                             class="absolute end-0 mt-1 w-44 bg-white text-gray-800 rounded-lg shadow-pop py-1 text-sm z-50">
                            <a href="{{ route('profile.edit') }}" class="block px-3 py-2 hover:bg-gray-100">Profile</a>
                            <a href="{{ route('dashboard') }}" class="block px-3 py-2 hover:bg-gray-100">Management</a>
                            <hr class="my-1">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="block w-full text-left px-3 py-2 hover:bg-gray-100 text-danger">Log out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            {{-- Page content (no sidebar, no extra header) --}}
            <main class="flex-1 overflow-auto">
                {{ $slot }}
            </main>
        </div>

        @stack('scripts')
    </body>
</html>
