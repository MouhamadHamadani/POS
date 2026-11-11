<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $customer->name }}</h2>
            <a href="{{ route('debts.index') }}" class="text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('debts.title') }}</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>@endif

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('customers.outstanding_balance') }}</div>
                <div class="text-2xl font-bold {{ $customer->balance > 0 ? 'text-danger' : 'text-success' }} mt-1"><x-ltr>${{ number_format((float) $customer->balance, 2) }}</x-ltr></div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('debts.cols.overdue') }}</div>
                <div class="text-2xl font-bold {{ $overdue > 0 ? 'text-danger' : 'text-gray-800' }} mt-1"><x-ltr>${{ number_format($overdue, 2) }}</x-ltr></div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('customers.limit') }}</div>
                <div class="text-2xl font-bold text-gray-800 mt-1"><x-ltr>${{ number_format((float) $customer->credit_limit, 2) }}</x-ltr></div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('customers.phone') }}</div>
                <div class="text-lg font-bold text-gray-800 mt-1">@if ($customer->phone)<x-ltr>{{ $customer->phone }}</x-ltr>@else {{ __('customers.no_phone') }} @endif</div>
            </div>
        </div>

        @include('debts._tabs')
    </div>
</x-app-layout>
