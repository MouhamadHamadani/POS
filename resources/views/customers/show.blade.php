<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $customer->name }}</h2>
            <div class="flex gap-2">
                <a href="{{ route('customers.edit', $customer) }}" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">{{ __('common.edit') }}</a>
                <a href="{{ route('customers.index') }}" class="px-3 py-2 text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('common.back') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>@endif

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('customers.outstanding_balance') }}</div>
                <div class="text-2xl font-bold {{ $customer->balance > 0 ? 'text-danger' : 'text-success' }} mt-1"><x-ltr>${{ number_format((float) $customer->balance, 2) }}</x-ltr></div>
                <div class="text-xs text-gray-500 mt-1">{{ __('customers.limit') }}: <x-ltr>${{ number_format((float) $customer->credit_limit, 2) }}</x-ltr></div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('customers.loyalty_points') }}</div>
                <div class="text-2xl font-bold text-accent mt-1"><x-ltr>{{ number_format($customer->loyalty_points) }}</x-ltr></div>
                <div class="text-xs text-gray-500 mt-1">{{ __('customers.tier') }}: {{ $customer->tierLabel() }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('customers.lifetime_spend') }}</div>
                <div class="text-2xl font-bold text-brand-700 mt-1"><x-ltr>${{ number_format($totalSpent, 2) }}</x-ltr></div>
                <div class="text-xs text-gray-500 mt-1">{{ trans_choice('customers.sales_count', $salesCount) }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('customers.group') }}</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">{{ $customer->groupLabel() }}</div>
                <div class="text-xs text-gray-500 mt-1">@if ($customer->phone)<x-ltr>{{ $customer->phone }}</x-ltr>@else {{ __('customers.no_phone') }} @endif</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-white rounded-lg shadow-card p-5">
                <h3 class="font-semibold mb-3 text-sm">{{ __('customers.record_payment') }}</h3>
                @if ($customer->balance > 0)
                    <form method="POST" action="{{ route('customers.payment', $customer) }}" class="space-y-3 text-sm">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-gray-500">{{ __('customers.amount_usd') }}</label>
                                <input type="number" step="0.01" min="0.01" max="{{ $customer->balance }}" name="amount_usd" dir="ltr" required class="w-full border-gray-300 rounded text-sm" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500">{{ __('customers.method') }}</label>
                                <select name="method" class="w-full border-gray-300 rounded text-sm" required>
                                    @foreach (['cash_usd', 'cash_lbp', 'card', 'bank_transfer', 'other'] as $m)
                                        <option value="{{ $m }}">{{ __("enums.payment_method.$m") }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('customers.reference') }}</label>
                            <input type="text" name="reference" dir="ltr" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('common.notes') }}</label>
                            <textarea name="notes" dir="auto" rows="2" class="w-full border-gray-300 rounded text-sm"></textarea>
                        </div>
                        <button class="w-full px-4 py-2 bg-success text-white rounded text-sm hover:bg-green-700">{{ __('customers.record_payment_btn') }}</button>
                    </form>
                @else
                    <p class="text-sm text-gray-500">{{ __('customers.no_balance') }}</p>
                @endif
            </div>

            <div class="bg-white rounded-lg shadow-card p-5">
                <h3 class="font-semibold mb-3 text-sm">{{ __('customers.recent_loyalty') }}</h3>
                <table class="w-full text-xs">
                    <thead class="text-gray-500 text-xs uppercase">
                        <tr class="border-b"><th class="text-start py-2">{{ __('common.type') }}</th><th class="text-end">{{ __('customers.points_col') }}</th><th class="text-end">{{ __('customers.balance') }}</th><th class="text-end">{{ __('dashboard.when') }}</th></tr>
                    </thead>
                    <tbody class="divide-y">
                    @forelse ($loyalty as $lt)
                        <tr>
                            <td class="py-2"><span class="text-xs px-2 py-0.5 rounded {{ $lt->points > 0 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">{{ $lt->typeLabel() }}</span></td>
                            <td class="text-end"><x-ltr>{{ $lt->points > 0 ? '+' : '' }}{{ $lt->points }}</x-ltr></td>
                            <td class="text-end text-gray-700"><x-ltr>{{ $lt->balance_after }}</x-ltr></td>
                            <td class="text-end text-gray-500">{{ $lt->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('customers.no_loyalty') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-card p-5">
            <h3 class="font-semibold mb-3 text-sm">{{ __('customers.recent_sales') }}</h3>
            <table class="w-full text-sm">
                <thead class="text-gray-500 text-xs uppercase">
                    <tr class="border-b"><th class="text-start py-2">{{ __('dashboard.receipt') }}</th><th class="text-start">{{ __('customers.method') }}</th><th class="text-end">{{ __('dashboard.total') }}</th><th class="text-end">{{ __('common.status') }}</th><th class="text-end">{{ __('dashboard.when') }}</th></tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($sales as $s)
                    <tr>
                        <td class="py-2 font-mono text-xs"><x-receipt-link :sale="$s" /></td>
                        <td class="text-xs">{{ $s->paymentMethodLabel() }}</td>
                        <td class="text-end font-medium"><x-ltr>${{ number_format((float) $s->total_usd, 2) }}</x-ltr></td>
                        <td class="text-end text-xs">{{ $s->statusLabel() }}</td>
                        <td class="text-end text-gray-500">{{ $s->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-gray-400">{{ __('dashboard.no_sales') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
