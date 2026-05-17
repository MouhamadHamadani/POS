<form method="post" action="{{ route('profile.update') }}" class="space-y-4 max-w-md">
    @csrf
    @method('patch')

    <div>
        <x-input-label for="name" :value="__('Full Name')" />
        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus />
        <x-input-error class="mt-2" :messages="$errors->get('name')" />
    </div>

    <div>
        <x-input-label for="username" :value="__('Username')" />
        <x-text-input id="username" name="username" type="text" class="mt-1 block w-full" :value="old('username', $user->username)" required />
        <x-input-error class="mt-2" :messages="$errors->get('username')" />
    </div>

    <div>
        <x-input-label for="email" :value="__('Email (optional)')" />
        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" />
        <x-input-error class="mt-2" :messages="$errors->get('email')" />
    </div>

    <div>
        <x-input-label for="language" :value="__('Language')" />
        <select id="language" name="language" class="mt-1 block w-full border-gray-300 rounded text-sm">
            <option value="en" @selected(old('language', $user->language) === 'en')>English</option>
            <option value="ar" @selected(old('language', $user->language) === 'ar')>العربية (Arabic)</option>
        </select>
        <x-input-error class="mt-2" :messages="$errors->get('language')" />
    </div>

    <x-primary-button>{{ __('Save') }}</x-primary-button>
</form>
