<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('Users') }}</h2>
            <a href="{{ route('users.create') }}" class="px-3 py-2 text-sm bg-brand-700 text-white rounded hover:bg-brand-800">+ New User</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))
            <div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="bg-white rounded-lg shadow-card p-4">
            <form method="GET" class="flex flex-wrap gap-2 items-end text-sm">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs text-gray-500">Search</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, username, email"
                           class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Role</label>
                    <select name="role" class="border-gray-300 rounded text-sm">
                        <option value="">All</option>
                        @foreach (['admin' => 'Admin', 'manager' => 'Manager', 'cashier' => 'Cashier', 'stock' => 'Stock'] as $v => $l)
                            <option value="{{ $v }}" @selected(request('role') === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="px-3 py-1.5 bg-gray-800 text-white rounded text-xs">Filter</button>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-card overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-left">Name</th>
                        <th class="p-3 text-left">Username</th>
                        <th class="p-3 text-left">Email</th>
                        <th class="p-3 text-left">Role</th>
                        <th class="p-3 text-left">Lang</th>
                        <th class="p-3 text-left">Last login</th>
                        <th class="p-3 text-center">Status</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($users as $u)
                        <tr class="@unless ($u->is_active) bg-gray-50 text-gray-500 @endunless">
                            <td class="p-3">{{ $u->name }}</td>
                            <td class="p-3 font-mono text-xs">{{ $u->username }}</td>
                            <td class="p-3 text-xs">{{ $u->email ?? '—' }}</td>
                            <td class="p-3"><span class="px-2 py-0.5 rounded text-xs bg-brand-100 text-brand-700">{{ ucfirst($u->role) }}</span></td>
                            <td class="p-3 uppercase text-xs">{{ $u->language }}</td>
                            <td class="p-3 text-xs text-gray-500">{{ $u->last_login_at?->diffForHumans() ?? '—' }}</td>
                            <td class="p-3 text-center">
                                @if ($u->is_active)
                                    <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded">Active</span>
                                @else
                                    <span class="text-xs bg-gray-200 text-gray-600 px-2 py-0.5 rounded">Inactive</span>
                                @endif
                            </td>
                            <td class="p-3 text-right space-x-2 whitespace-nowrap">
                                <a href="{{ route('users.edit', $u) }}" class="text-blue-600 hover:underline text-xs">Edit</a>
                                <form method="POST" action="{{ route('users.toggle', $u) }}" class="inline">
                                    @csrf
                                    <button class="text-xs {{ $u->is_active ? 'text-orange-600' : 'text-green-600' }} hover:underline">
                                        {{ $u->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-10 text-center text-gray-500">No users found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $users->links() }}
    </div>
</x-app-layout>
