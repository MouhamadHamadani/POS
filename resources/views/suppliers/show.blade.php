<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $supplier->name }}</h2>
            <div class="flex gap-2">
                <a href="{{ route('suppliers.edit', $supplier) }}" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">{{ __('common.edit') }}</a>
                <a href="{{ route('purchases.create', ['supplier' => $supplier->id]) }}" class="px-3 py-2 text-sm bg-brand-700 text-white rounded hover:bg-brand-800">{{ __('suppliers.new_po') }}</a>
                <a href="{{ route('suppliers.index') }}" class="px-3 py-2 text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('common.back') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>@endif

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('customers.outstanding_balance') }}</div>
                <div class="text-2xl font-bold {{ $supplier->balance > 0 ? 'text-warning' : 'text-success' }} mt-1"><x-ltr>${{ number_format((float) $supplier->balance, 2) }}</x-ltr></div>
                <div class="text-xs text-gray-500 mt-1">{{ __('suppliers.terms') }}: {{ \App\Support\EnumLabel::get('payment_terms', $supplier->payment_terms) }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('suppliers.lifetime_purchases') }}</div>
                <div class="text-2xl font-bold text-brand-700 mt-1"><x-ltr>${{ number_format($totalSpent, 2) }}</x-ltr></div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('suppliers.contact') }}</div>
                <div class="text-sm mt-1">{{ $supplier->contact_person ?? '—' }}</div>
                <div class="text-xs text-gray-500 mt-1"><x-ltr>{{ $supplier->phone ?? '' }} {{ $supplier->email ? '· ' . $supplier->email : '' }}</x-ltr></div>
            </div>
        </div>

        @if ($supplier->balance > 0)
        <div class="bg-white rounded-lg shadow-card p-5">
            <h3 class="font-semibold mb-3 text-sm">{{ __('suppliers.record_payment') }}</h3>
            <form method="POST" action="{{ route('suppliers.payment', $supplier) }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end text-sm">
                @csrf
                <div>
                    <label class="block text-xs text-gray-500">{{ __('customers.amount_usd') }}</label>
                    <input type="number" step="0.01" min="0.01" max="{{ $supplier->balance }}" name="amount_usd" dir="ltr" required class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">{{ __('customers.method') }}</label>
                    <select name="method" class="w-full border-gray-300 rounded text-sm" required>
                        @foreach (['cash_usd', 'cash_lbp', 'card', 'bank_transfer', 'cheque', 'other'] as $m)
                            <option value="{{ $m }}">{{ __("enums.payment_method.$m") }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">{{ __('suppliers.reference') }}</label>
                    <input type="text" name="reference" dir="ltr" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <button class="px-3 py-2 bg-success text-white rounded text-sm hover:bg-green-700">{{ __('suppliers.record') }}</button>
            </form>
        </div>
        @endif

        <div class="bg-white rounded-lg shadow-card p-5">
            <h3 class="font-semibold mb-3 text-sm">{{ __('suppliers.recent_pos') }}</h3>
            <table class="w-full text-sm">
                <thead class="text-gray-500 text-xs uppercase">
                    <tr class="border-b"><th class="text-start py-2">{{ __('suppliers.po_number') }}</th><th class="text-start">{{ __('common.status') }}</th><th class="text-end">{{ __('dashboard.total') }}</th><th class="text-end">{{ __('suppliers.expected') }}</th><th></th></tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($pos as $po)
                    <tr>
                        <td class="py-2 font-mono text-xs"><x-ltr>{{ $po->po_number }}</x-ltr></td>
                        <td><span class="text-xs px-2 py-0.5 rounded bg-brand-100 text-brand-700">{{ $po->statusLabel() }}</span></td>
                        <td class="text-end font-medium"><x-ltr>${{ number_format((float) $po->total_usd, 2) }}</x-ltr></td>
                        <td class="text-end text-gray-500">@if ($po->expected_at)<x-ltr>{{ $po->expected_at->format('Y-m-d') }}</x-ltr>@else — @endif</td>
                        <td class="text-end text-xs"><a href="{{ route('purchases.show', $po) }}" class="text-brand-600">{{ __('common.view') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-gray-400">{{ __('suppliers.no_pos') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
