<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('Suppliers') }}</h2>
            <a href="{{ route('suppliers.create') }}" class="px-3 py-2 text-sm bg-brand-700 text-white rounded hover:bg-brand-800">{{ __('suppliers.new_supplier') }}</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif

        <div class="bg-white rounded-lg shadow-card p-4">
            <form method="GET" class="flex flex-wrap gap-2 items-end text-sm">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs text-gray-500">{{ __('common.search') }}</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('suppliers.search_placeholder') }}" dir="auto" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <label class="text-xs text-gray-500 flex items-center gap-1">
                    <input type="checkbox" name="with_balance" value="1" @checked(request('with_balance'))>
                    {{ __('customers.has_balance') }}
                </label>
                <button class="px-3 py-1.5 bg-gray-800 text-white rounded text-xs">{{ __('common.filter') }}</button>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-card overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-start">{{ __('common.name') }}</th>
                        <th class="p-3 text-start">{{ __('suppliers.contact') }}</th>
                        <th class="p-3 text-start">{{ __('common.phone') }}</th>
                        <th class="p-3 text-start">{{ __('suppliers.terms') }}</th>
                        <th class="p-3 text-end">{{ __('customers.balance') }}</th>
                        <th class="p-3 text-end"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($suppliers as $sp)
                        <tr class="hover:bg-gray-50">
                            <td class="p-3">
                                <a href="{{ route('suppliers.show', $sp) }}" class="font-medium text-brand-700 hover:underline">{{ $sp->name }}</a>
                                @if ($sp->name_ar) <div class="text-xs text-gray-500" dir="rtl">{{ $sp->name_ar }}</div>@endif
                            </td>
                            <td class="p-3 text-xs">{{ $sp->contact_person ?? '—' }}</td>
                            <td class="p-3 text-xs text-gray-700">@if ($sp->phone)<x-ltr>{{ $sp->phone }}</x-ltr>@else — @endif</td>
                            <td class="p-3"><span class="px-2 py-0.5 rounded text-xs bg-brand-100 text-brand-700">{{ \App\Support\EnumLabel::get('payment_terms', $sp->payment_terms) }}</span></td>
                            <td class="p-3 text-end {{ $sp->balance > 0 ? 'text-warning font-bold' : 'text-gray-700' }}"><x-ltr>${{ number_format((float) $sp->balance, 2) }}</x-ltr></td>
                            <td class="p-3 text-end text-xs">
                                <a href="{{ route('suppliers.show', $sp) }}" class="text-brand-600 hover:underline">{{ __('common.view') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-10 text-center text-gray-500">{{ __('suppliers.none_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $suppliers->links() }}
    </div>
</x-app-layout>
