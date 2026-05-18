<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('Start a Return') }}</h2>
            <a href="{{ route('returns.index') }}" class="text-sm text-gray-600 hover:underline">← All returns</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="bg-white rounded-lg shadow-card p-6">
            <p class="text-sm text-gray-600 mb-4">Look up the original sale by receipt number or by the customer's phone number.</p>
            <form method="POST" action="{{ route('returns.find-sale') }}" class="flex gap-2">
                @csrf
                <input type="text" name="query" autofocus required placeholder="REC-2026-00001 or +96170123456"
                       class="flex-1 border-gray-300 rounded text-sm" />
                <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">Find Sale</button>
            </form>
        </div>
    </div>
</x-app-layout>
