<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Point of Sale') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <p class="text-gray-700">POS sales screen scaffold &mdash; Module 04 implementation goes here.</p>
                <p class="mt-2 text-sm text-gray-500">Cart, product grid, payment panel, barcode listener, and Alpine.js <code>posApp()</code> state will live in this view.</p>
            </div>
        </div>
    </div>
</x-app-layout>
