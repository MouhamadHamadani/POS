<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Profile') }}</h2></x-slot>

    <div class="py-6 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        @if (session('status') === 'profile-updated')
            <div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ __('profile.saved') }}</div>
        @endif
        @if (session('status') === 'password-updated')
            <div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ __('profile.password_updated') }}</div>
        @endif
        @if (session('status') === 'pin-updated')
            <div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ __('profile.pin_updated') }}</div>
        @endif

        <section class="bg-white rounded-lg shadow-card p-6">
            <h3 class="font-semibold text-gray-900 mb-4">{{ __('profile.account_info') }}</h3>
            @include('profile.partials.update-profile-information-form')
        </section>

        @unless (\App\Support\Demo::enabled())
            <section class="bg-white rounded-lg shadow-card p-6">
                <h3 class="font-semibold text-gray-900 mb-1">{{ __('profile.my_preferences') }}</h3>
                <p class="text-sm text-gray-500 mb-4">{{ __('profile.ui_scale_help') }}</p>
                <div class="mb-1 text-sm font-medium text-gray-700">{{ __('profile.ui_scale') }}</div>
                <x-ui-scale-switcher />
            </section>
        @endunless

        <section class="bg-white rounded-lg shadow-card p-6">
            <h3 class="font-semibold text-gray-900 mb-4">{{ __('profile.change_password') }}</h3>
            @include('profile.partials.update-password-form')
        </section>

        <section class="bg-white rounded-lg shadow-card p-6">
            <h3 class="font-semibold text-gray-900 mb-4">{{ __('profile.cashier_pin') }}</h3>
            <p class="text-sm text-gray-500 mb-4">{{ __('profile.pin_help') }}</p>
            <form method="POST" action="{{ route('profile.pin') }}" class="space-y-4 max-w-sm">
                @csrf
                @method('PATCH')

                <div>
                    <x-input-label for="current_password" :value="__('profile.current_password')" />
                    <x-text-input id="current_password" name="current_password" type="password" dir="ltr" required autocomplete="current-password" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('current_password')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="pin" :value="__('profile.new_pin')" />
                    <x-text-input id="pin" name="pin" type="password" dir="ltr" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4" required class="block mt-1 w-32 text-center text-lg tracking-widest" />
                    <x-input-error :messages="$errors->get('pin')" class="mt-2" />
                </div>

                <x-primary-button>{{ __('profile.save_pin') }}</x-primary-button>
            </form>
        </section>
    </div>
</x-app-layout>
