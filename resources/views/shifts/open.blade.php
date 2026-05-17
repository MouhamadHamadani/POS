<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Open Shift') }}</h2></x-slot>

    <div class="py-8 max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            @if (session('warning'))
                <div class="mb-4 p-3 bg-yellow-50 text-yellow-800 rounded">{{ session('warning') }}</div>
            @endif

            <p class="text-gray-700 mb-6">Enter the cash you start with in the drawer. Both currencies are required (use 0 if you have none of one).</p>

            <form method="POST" action="{{ route('shifts.open.store') }}" class="space-y-4">
                @csrf

                <div>
                    <x-input-label for="opening_cash_usd" value="Opening Cash (USD)" />
                    <x-text-input id="opening_cash_usd" name="opening_cash_usd" type="number" step="0.01" min="0"
                                  value="{{ old('opening_cash_usd', 0) }}" required class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('opening_cash_usd')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="opening_cash_lbp" value="Opening Cash (LBP)" />
                    <x-text-input id="opening_cash_lbp" name="opening_cash_lbp" type="number" step="1000" min="0"
                                  value="{{ old('opening_cash_lbp', 0) }}" required class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('opening_cash_lbp')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="notes" value="Notes (optional)" />
                    <textarea id="notes" name="notes" rows="2" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">{{ old('notes') }}</textarea>
                </div>

                <div class="flex items-center justify-end">
                    <x-primary-button>Open Shift &amp; Go to POS</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
