@php
    $statusClass = match($return->status) {
        'pending' => 'bg-orange-100 text-orange-700',
        'approved' => 'bg-brand-100 text-brand-700',
        'completed' => 'bg-green-100 text-green-700',
        'rejected' => 'bg-red-100 text-red-700',
        default => 'bg-gray-100 text-gray-700',
    };
    $canApprove = auth()->user()->hasRole('super_admin', 'admin', 'manager') && $return->status === 'pending';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">
                <x-ltr>{{ $return->return_number }}</x-ltr>
                <span class="ms-2 text-xs px-2 py-0.5 rounded {{ $statusClass }}">{{ $return->statusLabel() }}</span>
            </h2>
            <a href="{{ route('returns.index') }}" class="text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('returns.all_returns') }}</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>@endif

        <div class="bg-white rounded-lg shadow-card p-6 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
            <div><dt class="text-xs text-gray-500">{{ __('returns.original_sale') }}</dt><dd class="font-mono text-xs">@if ($return->sale?->receipt_number)<x-receipt-link :sale="$return->sale" />@else — @endif</dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('returns.customer') }}</dt><dd>{{ $return->sale?->customer?->name ?? __('returns.walk_in') }}</dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('returns.reason') }}</dt><dd>{{ $return->reasonLabel() }}</dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('returns.refund_method') }}</dt><dd>{{ $return->refundMethodLabel() }}</dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('returns.refund_usd') }}</dt><dd class="font-medium text-brand-700"><x-ltr>${{ number_format((float) $return->refund_amount_usd, 2) }}</x-ltr></dd></div>
            @if ($return->refund_method === 'cash_lbp')
                <div><dt class="text-xs text-gray-500">{{ __('returns.refund_lbp') }}</dt><dd><x-ltr>{{ number_format((float) $return->refund_amount_lbp) }}</x-ltr></dd></div>
            @endif
            <div><dt class="text-xs text-gray-500">{{ __('purchases.created_by') }}</dt><dd>{{ $return->user?->name }}</dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('returns.approved_by') }}</dt><dd>{{ $return->approver?->name ?? '—' }}</dd></div>
        </div>

        @if ($canApprove)
            <div class="bg-orange-50 border border-orange-200 rounded-lg p-4 flex justify-between items-center">
                <span class="text-sm text-orange-800">{{ __('returns.pending_approval') }}</span>
                <div class="flex gap-2">
                    <form method="POST" action="{{ route('returns.reject', $return) }}">
                        @csrf
                        <button class="px-3 py-1.5 bg-red-100 text-red-700 rounded text-xs hover:bg-red-200"
                                onclick="return confirm(@js(__('returns.confirm_reject')))">{{ __('returns.reject') }}</button>
                    </form>
                    <form method="POST" action="{{ route('returns.approve', $return) }}">
                        @csrf
                        <button class="px-3 py-1.5 bg-success text-white rounded text-xs hover:bg-green-700">{{ __('returns.approve_refund') }}</button>
                    </form>
                </div>
            </div>
        @endif

        <div class="bg-white rounded-lg shadow-card p-6">
            <h3 class="font-semibold mb-3 text-sm">{{ __('returns.items_returned') }}</h3>
            <table class="min-w-full text-sm">
                <thead class="text-xs uppercase text-gray-500 border-b">
                    <tr>
                        <th class="text-start py-2">{{ __('dashboard.product') }}</th>
                        <th class="text-end py-2">{{ __('purchases.qty') }}</th>
                        <th class="text-center py-2">{{ __('returns.restocked') }}</th>
                        <th class="text-center py-2">{{ __('returns.condition') }}</th>
                        <th class="text-end py-2">{{ __('returns.refund') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @foreach ($return->items as $i)
                    <tr>
                        <td class="py-2">{{ $i->product?->localName() ?? '—' }}</td>
                        <td class="py-2 text-end"><x-ltr>{{ rtrim(rtrim(number_format((float) $i->qty_returned, 4, '.', ''), '0'), '.') }}</x-ltr></td>
                        <td class="py-2 text-center">{{ $i->restock ? __('common.yes') : __('common.no') }}</td>
                        <td class="py-2 text-center text-xs">{{ $i->conditionLabel() }}</td>
                        <td class="py-2 text-end font-medium"><x-ltr>${{ number_format((float) $i->refund_amount_usd, 2) }}</x-ltr></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if ($return->reason_note || $return->notes)
            <div class="bg-white rounded-lg shadow-card p-6 text-sm">
                @if ($return->reason_note)
                    <h3 class="font-semibold mb-2 text-sm">{{ __('returns.reason_note') }}</h3>
                    <p class="text-gray-700">{{ $return->reason_note }}</p>
                @endif
                @if ($return->notes)
                    <h3 class="font-semibold mt-3 mb-2 text-sm">{{ __('common.notes') }}</h3>
                    <p class="text-gray-700 whitespace-pre-wrap">{{ $return->notes }}</p>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>
