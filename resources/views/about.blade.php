<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('about.title') }}</h2></x-slot>

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
                    'version'   => config('nativephp.version'),
                    'build'     => config('app.env'),
                    'app_id'    => config('nativephp.app_id'),
                    'publisher' => config('nativephp.author'),
                    'database'  => basename(config('database.connections.sqlite.database')),
                    'timezone'  => config('app.timezone'),
                ] as $key => $value)
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="text-gray-500">{{ __("about.$key") }}</dt>
                        <dd class="text-gray-800 font-mono text-xs text-end break-all" dir="ltr">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="text-xs text-gray-400">{{ config('nativephp.copyright') }}</p>
        </div>
    </div>
</x-app-layout>
