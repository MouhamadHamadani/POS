<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Close Shift') }}</h2></x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="text-lg font-medium mb-4">Shift summary</h3>
            <dl class="grid grid-cols-2 gap-4 mb-6 text-sm">
                <div><dt class="text-gray-500">Opened</dt><dd class="font-medium">{{ $shift->opened_at }}</dd></div>
                <div><dt class="text-gray-500">Sales count</dt><dd class="font-medium">{{ $totals['sales_count'] }}</dd></div>
                <div><dt class="text-gray-500">Revenue (USD)</dt><dd class="font-medium">${{ number_format($totals['total_revenue_usd'], 2) }}</dd></div>
                <div><dt class="text-gray-500">Opening (USD)</dt><dd class="font-medium">${{ number_format((float) $shift->opening_cash_usd, 2) }}</dd></div>
                <div><dt class="text-gray-500">Expected cash (USD)</dt><dd class="font-medium">${{ number_format((float) $shift->opening_cash_usd + $totals['cash_in_usd'], 2) }}</dd></div>
                <div><dt class="text-gray-500">Expected cash (LBP)</dt><dd class="font-medium">{{ number_format((float) $shift->opening_cash_lbp + $totals['cash_in_lbp']) }}</dd></div>
            </dl>

            <form method="POST" action="{{ route('shifts.close.store') }}" class="space-y-4">
                @csrf

                <div>
                    <x-input-label for="closing_cash_usd" value="Counted Cash (USD)" />
                    <x-text-input id="closing_cash_usd" name="closing_cash_usd" type="number" step="0.01" min="0"
                                  value="{{ old('closing_cash_usd', 0) }}" required class="block mt-1 w-full" />
                </div>
                <div>
                    <x-input-label for="closing_cash_lbp" value="Counted Cash (LBP)" />
                    <x-text-input id="closing_cash_lbp" name="closing_cash_lbp" type="number" step="1000" min="0"
                                  value="{{ old('closing_cash_lbp', 0) }}" required class="block mt-1 w-full" />
                </div>
                <div>
                    <x-input-label for="notes" value="Notes (optional)" />
                    <textarea id="notes" name="notes" rows="2" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">{{ old('notes') }}</textarea>
                </div>

                <div class="flex items-center justify-end">
                    <x-primary-button class="!bg-red-600 hover:!bg-red-700">Close Shift</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
