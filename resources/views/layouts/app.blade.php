<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ ($title ?? 'LebaSouk') . \App\Support\Demo::titleSuffix() }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="{{ app()->getLocale() === 'ar' ? 'font-arabic' : 'font-sans' }} antialiased text-gray-900 bg-slate-50">
        @includeWhen(\App\Support\Demo::enabled(), 'partials.demo-banner')

        <div class="flex min-h-screen">
            <x-sidebar />

            <div class="flex-1 flex flex-col min-w-0">
                @isset($header)
                    <header class="bg-white shadow-card border-b border-gray-100">
                        <div class="px-6 py-4">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <main class="flex-1">
                    {{ $slot }}
                </main>
            </div>
        </div>
        @stack('scripts')
    </body>
</html>
