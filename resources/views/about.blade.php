<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('About') }}</h2></x-slot>

    <div class="py-6 max-w-lg mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg shadow-card p-6 space-y-4">
            <div>
                <div class="text-2xl font-semibold text-brand-700">
                    {{ config('app.name') }}{{ \App\Support\Demo::titleSuffix() }}
                </div>
                <p class="text-sm text-gray-500 mt-1">{{ config('nativephp.description') }}</p>
            </div>

            <dl class="text-sm divide-y divide-gray-100">
                @foreach ([
                    'Version'   => config('nativephp.version'),
                    'Build'     => config('app.env'),
                    'App ID'    => config('nativephp.app_id'),
                    'Publisher' => config('nativephp.author'),
                    'Database'  => basename(config('database.connections.sqlite.database')),
                    'Time zone' => config('app.timezone'),
                ] as $label => $value)
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd class="text-gray-800 font-mono text-xs text-right break-all">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="text-xs text-gray-400">{{ config('nativephp.copyright') }}</p>
        </div>
    </div>
</x-app-layout>
