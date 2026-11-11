<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('debts.title') }}</h2></x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>@endif

        @include('debts._list', ['exportRoute' => 'debts.index'])
    </div>
</x-app-layout>
