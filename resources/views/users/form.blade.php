<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $isEdit ? "Edit User" : "New User" }}</h2>
            <a href="{{ route('users.index') }}" class="text-sm text-gray-600 hover:underline">← Back</a>
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

        <form method="POST" action="{{ $isEdit ? route('users.update', $user) : route('users.store') }}"
              class="bg-white rounded-lg shadow-card p-6 space-y-4">
            @csrf
            @if ($isEdit) @method('PUT') @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Full name *</label>
                    <input type="text" name="name" value="{{ old('name', $user->name) }}" required class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Username *</label>
                    <input type="text" name="username" value="{{ old('username', $user->username) }}" required class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Email (optional)</label>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Role *</label>
                    <select name="role" required class="w-full border-gray-300 rounded text-sm">
                        @foreach (['admin' => 'Admin', 'manager' => 'Manager', 'cashier' => 'Cashier', 'stock' => 'Stock Keeper'] as $v => $l)
                            <option value="{{ $v }}" @selected(old('role', $user->role) === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Language</label>
                    <select name="language" class="w-full border-gray-300 rounded text-sm">
                        <option value="en" @selected(old('language', $user->language) === 'en')>English</option>
                        <option value="ar" @selected(old('language', $user->language) === 'ar')>العربية</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Max discount % at POS</label>
                    <input type="number" step="0.01" min="0" max="100" name="max_discount_pct"
                           value="{{ old('max_discount_pct', $user->max_discount_pct ?? 0) }}"
                           class="w-full border-gray-300 rounded text-sm" />
                </div>

                @unless ($isEdit)
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Password *</label>
                        <input type="password" name="password" required class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Confirm password *</label>
                        <input type="password" name="password_confirmation" required class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">PIN (4 digits, optional)</label>
                        <input type="text" name="pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4"
                               class="w-full border-gray-300 rounded text-sm tracking-widest" />
                    </div>
                @endunless

                <div class="md:col-span-2 flex items-center gap-2">
                    <input type="checkbox" name="is_active" id="is_active" value="1" @checked(old('is_active', $user->is_active))>
                    <label for="is_active" class="text-sm">Active</label>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('users.index') }}" class="px-4 py-2 bg-gray-100 rounded text-sm">Cancel</a>
                <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">
                    {{ $isEdit ? 'Save Changes' : 'Create User' }}
                </button>
            </div>
        </form>

        @if ($isEdit)
            <div class="bg-white rounded-lg shadow-card p-6">
                <h3 class="font-semibold mb-3">Reset password</h3>
                <form method="POST" action="{{ route('users.reset-password', $user) }}" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">New password</label>
                        <input type="password" name="password" required class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Confirm</label>
                        <input type="password" name="password_confirmation" required class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <button class="px-3 py-2 bg-orange-600 text-white rounded text-sm hover:bg-orange-700">Reset Password</button>
                </form>
            </div>

            <div class="bg-white rounded-lg shadow-card p-6">
                <h3 class="font-semibold mb-3">Reset PIN</h3>
                <form method="POST" action="{{ route('users.reset-pin', $user) }}" class="flex gap-3 items-end">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">New 4-digit PIN</label>
                        <input type="text" name="pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4" required
                               class="w-32 border-gray-300 rounded text-sm tracking-widest text-center" />
                    </div>
                    <button class="px-3 py-2 bg-orange-600 text-white rounded text-sm hover:bg-orange-700">Reset PIN</button>
                </form>
            </div>
        @endif
    </div>
</x-app-layout>
