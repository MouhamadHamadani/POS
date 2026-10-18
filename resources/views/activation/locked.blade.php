{{--
    Lock screen (see App\Http\Middleware\ActivationGate): trial expired, or paused
    by the provider. Reachable signed out — the people it is for cannot sign in
    to anything — and says nothing about the store's data beyond "it is safe".
--}}
<x-guest-layout>
    @php
        // A provider pause wins over a wrong date: fixing the clock would not lift it.
        $paused = \App\Support\Activation::lockReason() === \App\Support\Activation::REASON_MANUAL;
        $clock = ! $paused && \App\Support\Activation::clockRolledBack();
    @endphp

    <div class="text-center">
        <h1 class="text-lg font-semibold text-gray-900">{{ $paused ? __('activation.paused_title') : ($clock ? __('activation.clock_title') : __('activation.lock_title')) }}</h1>
        <p class="mt-2 text-sm text-gray-600">{{ $paused ? __('activation.paused_body') : ($clock ? __('activation.clock_body') : __('activation.lock_body')) }}</p>
    </div>

    <div class="mt-6 flex items-center justify-center gap-4 text-sm">
        @auth
            @if (auth()->user()->isSuperAdmin())
                <a href="{{ route('settings.index', ['tab' => 'plan']) }}" class="text-brand-700 hover:underline">{{ __('activation.open_activation') }}</a>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="text-gray-600 hover:underline">{{ __('Log Out') }}</button>
            </form>
        @else
            <a href="{{ route('login') }}" class="text-brand-700 hover:underline">{{ __('activation.provider_sign_in') }}</a>
        @endauth
    </div>
</x-guest-layout>
