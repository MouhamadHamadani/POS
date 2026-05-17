<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Profile') }}</h2></x-slot>

    <div class="py-6 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        @if (session('status') === 'profile-updated')
            <div class="p-3 bg-green-50 text-green-800 rounded text-sm">Profile saved.</div>
        @endif
        @if (session('status') === 'password-updated')
            <div class="p-3 bg-green-50 text-green-800 rounded text-sm">Password updated.</div>
        @endif
        @if (session('status') === 'pin-updated')
            <div class="p-3 bg-green-50 text-green-800 rounded text-sm">PIN updated.</div>
        @endif

        <section class="bg-white rounded-lg shadow-card p-6">
            <h3 class="font-semibold text-gray-900 mb-4">Account info</h3>
            @include('profile.partials.update-profile-information-form')
        </section>

        <section class="bg-white rounded-lg shadow-card p-6">
            <h3 class="font-semibold text-gray-900 mb-4">Change password</h3>
            @include('profile.partials.update-password-form')
        </section>

        <section class="bg-white rounded-lg shadow-card p-6">
            <h3 class="font-semibold text-gray-900 mb-4">Cashier PIN</h3>
            <p class="text-sm text-gray-500 mb-4">A 4-digit PIN can be used for quick cashier switching at the POS without re-entering your password.</p>
            <form method="POST" action="{{ route('profile.pin') }}" class="space-y-4 max-w-sm">
                @csrf
                @method('PATCH')

                <div>
                    <x-input-label for="current_password" value="Current password" />
                    <x-text-input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('current_password')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="pin" value="New 4-digit PIN" />
                    <x-text-input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4" required class="block mt-1 w-32 text-center text-lg tracking-widest" />
                    <x-input-error :messages="$errors->get('pin')" class="mt-2" />
                </div>

                <x-primary-button>Save PIN</x-primary-button>
            </form>
        </section>
    </div>
</x-app-layout>
