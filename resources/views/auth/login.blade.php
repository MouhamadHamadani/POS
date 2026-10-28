<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    {{-- No account exists, so no form on this page can succeed. See SuperAdmin::unprovisioned(). --}}
    @if (\App\Support\SuperAdmin::unprovisioned())
        <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800">
            {{ __('auth.not_provisioned') }}
        </div>
    @endif

    {{-- A token the server no longer knows (see App\Support\ErrorPages::tokenMismatch) lands here with a fresh one. --}}
    @if (session('expired'))
        <div role="alert" class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            {{ session('expired') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Username or Email -->
        <div>
            <x-input-label for="login" :value="__('Username or Email')" />
            <x-text-input dir="ltr" id="login" class="block mt-1 w-full" type="text" name="login" :value="old('login')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('login')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input dir="ltr" id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-brand-600 shadow-sm focus:ring-brand-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button class="ms-3">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>

    <script>
        // A login page left open for hours holds a token the server has forgotten (the session ran out, or
        // the window came back from the back/forward cache). Reload it for a fresh one, unless someone is typing.
        (() => {
            const fields = ['login', 'password'].map((id) => document.getElementById(id));
            const refresh = () => (fields.every((f) => !f.value) ? location.reload() : setTimeout(refresh, 60000));
            setTimeout(refresh, {{ max(5, (int) config('session.lifetime') - 5) }} * 60000);
            window.addEventListener('pageshow', (e) => { if (e.persisted) location.reload(); });
        })();
    </script>

    @includeWhen(\App\Support\Demo::enabled(), 'partials.demo-credentials')
</x-guest-layout>
