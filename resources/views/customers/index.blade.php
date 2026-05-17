<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('Customers') }}</h2>
            <a href="{{ route('customers.create') }}" class="px-3 py-2 text-sm bg-brand-700 text-white rounded hover:bg-brand-800">+ New Customer</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif

        <div class="bg-white rounded-lg shadow-card p-4">
            <form method="GET" class="flex flex-wrap gap-2 items-end text-sm">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs text-gray-500">Search</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, phone, email, company"
                           class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Group</label>
                    <select name="group" class="border-gray-300 rounded text-sm">
                        <option value="">All</option>
                        @foreach (['retail', 'wholesale', 'vip'] as $g)
                            <option value="{{ $g }}" @selected(request('group') === $g)>{{ ucfirst($g) }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="text-xs text-gray-500 flex items-center gap-1">
                    <input type="checkbox" name="with_balance" value="1" @checked(request('with_balance'))>
                    Has outstanding balance
                </label>
                <button class="px-3 py-1.5 bg-gray-800 text-white rounded text-xs">Filter</button>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-card overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-left">Name</th>
                        <th class="p-3 text-left">Phone</th>
                        <th class="p-3 text-left">Group</th>
                        <th class="p-3 text-right">Balance</th>
                        <th class="p-3 text-right">Credit Limit</th>
                        <th class="p-3 text-right">Loyalty</th>
                        <th class="p-3 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($customers as $c)
                        <tr class="hover:bg-gray-50">
                            <td class="p-3">
                                <a href="{{ route('customers.show', $c) }}" class="font-medium text-brand-700 hover:underline">{{ $c->name }}</a>
                                @if ($c->company_name) <div class="text-xs text-gray-500">{{ $c->company_name }}</div>@endif
                            </td>
                            <td class="p-3 text-xs text-gray-700">{{ $c->phone ?? '—' }}</td>
                            <td class="p-3"><span class="px-2 py-0.5 rounded text-xs bg-brand-100 text-brand-700">{{ ucfirst($c->customer_group) }}</span></td>
                            <td class="p-3 text-right {{ $c->balance > 0 ? 'text-danger font-bold' : 'text-gray-700' }}">${{ number_format((float) $c->balance, 2) }}</td>
                            <td class="p-3 text-right">${{ number_format((float) $c->credit_limit, 2) }}</td>
                            <td class="p-3 text-right">
                                {{ number_format($c->loyalty_points) }} pts
                                <span class="text-xs text-gray-500 ml-1">({{ ucfirst($c->loyalty_tier) }})</span>
                            </td>
                            <td class="p-3 text-right text-xs">
                                <a href="{{ route('customers.show', $c) }}" class="text-blue-600 hover:underline">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-10 text-center text-gray-500">No customers found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $customers->links() }}
    </div>
</x-app-layout>
