<x-guest-layout>
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-gray-900">Set up this machine</h1>
        <p class="mt-1 text-sm text-gray-600">
            This till has no accounts yet. Create the owner account — it has full
            access, including database backups. This screen closes for good once
            the account exists.
        </p>
    </div>

    <form method="POST" action="{{ route('setup.store') }}">
        @csrf

        <div>
            <x-input-label for="name" :value="__('Full name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name"
                          :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="username" :value="__('Username')" />
            <x-text-input id="username" class="block mt-1 w-full" type="text" name="username"
                          :value="old('username')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('username')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="email" :value="__('Email (optional)')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email"
                          :value="old('email')" autocomplete="email" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password"
                          required autocomplete="new-password" />
            <p class="mt-1 text-xs text-gray-500">At least 8 characters.</p>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm password')" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password"
                          name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="pin" :value="__('Till PIN (optional, 4 digits)')" />
            <x-text-input id="pin" class="block mt-1 w-full" type="text" name="pin"
                          :value="old('pin')" inputmode="numeric" maxlength="4" autocomplete="off" />
            <p class="mt-1 text-xs text-gray-500">Used for quick till actions such as authorising a discount.</p>
            <x-input-error :messages="$errors->get('pin')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="language" :value="__('Language')" />
            <select id="language" name="language"
                    class="block mt-1 w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">
                <option value="en" @selected(old('language', 'en') === 'en')>English</option>
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
