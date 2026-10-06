<x-guest-layout>
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-gray-900">{{ __('setup.title') }}</h1>
        <p class="mt-1 text-sm text-gray-600">
            {{ __('setup.intro') }}
        </p>
    </div>

    <form method="POST" action="{{ route('setup.store') }}">
        @csrf

        <div>
            <x-input-label for="name" :value="__('Full name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" dir="auto"
                          :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="username" :value="__('Username')" />
            <x-text-input id="username" class="block mt-1 w-full" type="text" name="username" dir="ltr"
                          :value="old('username')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('username')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="email" :value="__('Email (optional)')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" dir="ltr"
                          :value="old('email')" autocomplete="email" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" dir="ltr"
                          required autocomplete="new-password" />
            <p class="mt-1 text-xs text-gray-500">{{ __('setup.password_hint') }}</p>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm password')" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" dir="ltr"
                          name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="pin" :value="__('Till PIN (optional, 4 digits)')" />
            <x-text-input id="pin" class="block mt-1 w-full" type="text" name="pin" dir="ltr"
                          :value="old('pin')" inputmode="numeric" maxlength="4" autocomplete="off" />
            <p class="mt-1 text-xs text-gray-500">{{ __('setup.pin_hint') }}</p>
            <x-input-error :messages="$errors->get('pin')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="language" :value="__('Language')" />
            <select id="language" name="language"
                    class="block mt-1 w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">
                <option value="en" @selected(old('language', app()->getLocale()) === 'en')>English</option>
                <option value="ar" @selected(old('language') === 'ar')>العربية</option>
            </select>
            <x-input-error :messages="$errors->get('language')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-6">
            <x-primary-button>
                {{ __('Create owner account') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
