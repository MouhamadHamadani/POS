<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $isEdit ? __('users.edit_user') : __('users.new_user_title') }}</h2>
            <a href="{{ route('users.index') }}" class="text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('common.back') }}</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif
        @if (session('success'))
            <div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>
        @endif
        @if ($seatLimit ?? null)
            <div class="p-3 bg-amber-50 text-amber-800 rounded text-sm">{{ $seatLimit }}</div>
        @endif

        <form method="POST" action="{{ $isEdit ? route('users.update', $user) : route('users.store') }}"
              class="bg-white rounded-lg shadow-card p-6 space-y-4">
            @csrf
            @if ($isEdit) @method('PUT') @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('users.full_name') }} *</label>
                    <input type="text" name="name" dir="auto" value="{{ old('name', $user->name) }}" required class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('users.username') }} *</label>
                    <input type="text" name="username" dir="ltr" value="{{ old('username', $user->username) }}" required class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('users.email_optional') }}</label>
                    <input type="email" name="email" dir="ltr" value="{{ old('email', $user->email) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('users.role') }} *</label>
                    @php
                        // Privilege narrowing (who may assign what) intersected with
                        // the install's tier (which roles exist here at all). Mirrors
                        // UserController::assignableRoles() — the controller is the
                        // enforcing copy, this one only avoids offering dead options.
                        $roleOptions = array_intersect_key(
                            auth()->user()->isSuperAdmin() ? \App\Models\User::ROLES : \App\Models\User::STAFF_ROLES,
                            array_flip([...\App\Support\License::allowedRoles(), \App\Models\User::ROLE_SUPER_ADMIN]),
                        );
                    @endphp
                    {{-- A user whose role the plan no longer offers keeps it as an extra option: with no
                         selected option the browser would submit the first one and Save would change the role. --}}
                    @php $keptRole = $isEdit && ! isset($roleOptions[$user->role]) ? $user->role : null; @endphp
                    <select name="role" required class="w-full border-gray-300 rounded text-sm">
                        @foreach (array_keys($roleOptions) as $v)
                            <option value="{{ $v }}" @selected(old('role', $user->role) === $v)>{{ \App\Support\EnumLabel::get('role', $v) }}</option>
                        @endforeach
                        @if ($keptRole)
                            <option value="{{ $keptRole }}" @selected(old('role', $user->role) === $keptRole)>{{ __('users.role_not_in_plan', ['role' => \App\Support\EnumLabel::get('role', $keptRole)]) }}</option>
                        @endif
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('Language') }}</label>
                    <select name="language" class="w-full border-gray-300 rounded text-sm">
                        <option value="en" @selected(old('language', $user->language) === 'en')>English</option>
                        <option value="ar" @selected(old('language', $user->language) === 'ar')>العربية</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('users.max_discount') }}</label>
                    <input type="number" step="0.01" min="0" max="100" name="max_discount_pct" dir="ltr"
                           value="{{ old('max_discount_pct', $user->max_discount_pct ?? 0) }}"
                           class="w-full border-gray-300 rounded text-sm" />
                    <p class="mt-1 text-[11px] text-gray-500">{{ __('pos.users.max_discount_hint') }}</p>
                </div>

                @unless ($isEdit)
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('Password') }} *</label>
                        <input type="password" name="password" dir="ltr" required class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('Confirm password') }} *</label>
                        <input type="password" name="password_confirmation" dir="ltr" required class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('users.pin_optional') }}</label>
                        <input type="text" name="pin" dir="ltr" inputmode="numeric" pattern="[0-9]{4}" maxlength="4"
                               class="w-full border-gray-300 rounded text-sm tracking-widest" />
                    </div>
                @endunless

                <div class="md:col-span-2 flex items-center gap-2">
                    {{-- Hidden 0 first: an un-ticked box sends nothing, so old() would be empty and the stored value would come back after a validation error. --}}
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" id="is_active" value="1" @checked(old('is_active', $user->is_active))>
                    <label for="is_active" class="text-sm">{{ __('common.active') }}</label>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('users.index') }}" class="px-4 py-2 bg-gray-100 rounded text-sm">{{ __('common.cancel') }}</a>
                <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">
                    {{ $isEdit ? __('common.save_changes') : __('users.create_user') }}
                </button>
            </div>
        </form>

        @if ($isEdit)
            <div class="bg-white rounded-lg shadow-card p-6">
                <h3 class="font-semibold mb-3">{{ __('users.reset_password') }}</h3>
                <form method="POST" action="{{ route('users.reset-password', $user) }}" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('users.new_password') }}</label>
                        <input type="password" name="password" dir="ltr" required class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('users.confirm') }}</label>
                        <input type="password" name="password_confirmation" dir="ltr" required class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <button class="px-3 py-2 bg-orange-600 text-white rounded text-sm hover:bg-orange-700">{{ __('users.reset_password') }}</button>
                </form>
            </div>

            <div class="bg-white rounded-lg shadow-card p-6">
                <h3 class="font-semibold mb-3">{{ __('users.reset_pin') }}</h3>
                <form method="POST" action="{{ route('users.reset-pin', $user) }}" class="flex gap-3 items-end">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('users.new_pin') }}</label>
                        <input type="text" name="pin" dir="ltr" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4" required
                               class="w-32 border-gray-300 rounded text-sm tracking-widest text-center" />
                    </div>
                    <button class="px-3 py-2 bg-orange-600 text-white rounded text-sm hover:bg-orange-700">{{ __('users.reset_pin') }}</button>
                </form>
            </div>
        @endif
    </div>
</x-app-layout>
