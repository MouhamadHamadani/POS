@php
    $statusClass = match($return->status) {
        'pending' => 'bg-orange-100 text-orange-700',
        'approved' => 'bg-blue-100 text-blue-700',
        'completed' => 'bg-green-100 text-green-700',
        'rejected' => 'bg-red-100 text-red-700',
        default => 'bg-gray-100 text-gray-700',
    };
    $canApprove = in_array(auth()->user()->role, ['admin', 'manager'], true) && $return->status === 'pending';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">
                {{ $return->return_number }}
                <span class="ml-2 text-xs px-2 py-0.5 rounded {{ $statusClass }}">{{ ucfirst($return->status) }}</span>
            </h2>
            <a href="{{ route('returns.index') }}" class="text-sm text-gray-600 hover:underline">← All returns</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>@endif

        <div class="bg-white rounded-lg shadow-card p-6 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
            <div><dt class="text-xs text-gray-500">Original sale</dt><dd class="font-mono text-xs">{{ $return->sale?->receipt_number ?? '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Customer</dt><dd>{{ $return->sale?->customer?->name ?? 'Walk-in' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Reason</dt><dd>{{ ucfirst(str_replace('_', ' ', $return->reason)) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Refund method</dt><dd>{{ str_replace('_', ' ', $return->refund_method) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Refund (USD)</dt><dd class="font-medium text-brand-700">${{ number_format((float) $return->refund_amount_usd, 2) }}</dd></div>
            @if ($return->refund_method === 'cash_lbp')
                <div><dt class="text-xs text-gray-500">Refund (LBP)</dt><dd>{{ number_format((float) $return->refund_amount_lbp) }}</dd></div>
            @endif
            <div><dt class="text-xs text-gray-500">Created by</dt><dd>{{ $return->user?->name }}</dd></div>
            <div><dt class="text-xs text-gray-500">Approved by</dt><dd>{{ $return->approver?->name ?? '—' }}</dd></div>
        </div>

        @if ($canApprove)
            <div class="bg-orange-50 border border-orange-200 rounded-lg p-4 flex justify-between items-center">
                <span class="text-sm text-orange-800">This return is pending your approval.</span>
                <div class="flex gap-2">
                    <form method="POST" action="{{ route('returns.reject', $return) }}">
                        @csrf
                        <button class="px-3 py-1.5 bg-red-100 text-red-700 rounded text-xs hover:bg-red-200"
                                onclick="return confirm('Reject this return?')">Reject</button>
                    </form>
                    <form method="POST" action="{{ route('returns.approve', $return) }}">
                        @csrf
                        <button class="px-3 py-1.5 bg-success text-white rounded text-xs hover:bg-green-700">Approve &amp; Refund</button>
                    </form>
                </div>
            </div>
        @endif

        <div class="bg-white rounded-lg shadow-card p-6">
            <h3 class="font-semibold mb-3 text-sm">Items returned</h3>
            <table class="min-w-full text-sm">
                <thead class="text-xs uppercase text-gray-500 border-b">
                    <tr>
                        <th class="text-left py-2">Product</th>
                        <th class="text-right py-2">Qty</th>
                        <th class="text-center py-2">Restocked?</th>
                        <th class="text-center py-2">Condition</th>
                        <th class="text-right py-2">Refund</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @foreach ($return->items as $i)
                    <tr>
                        <td class="py-2">{{ $i->product?->name ?? '—' }}</td>
                        <td class="py-2 text-right">{{ rtrim(rtrim(number_format((float) $i->qty_returned, 4, '.', ''), '0'), '.') }}</td>
                        <td class="py-2 text-center">{{ $i->restock ? 'Yes' : 'No' }}</td>
                        <td class="py-2 text-center text-xs">{{ ucfirst($i->condition) }}</td>
                        <td class="py-2 text-right font-medium">${{ number_format((float) $i->refund_amount_usd, 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if ($return->reason_note || $return->notes)
            <div class="bg-white rounded-lg shadow-card p-6 text-sm">
                @if ($return->reason_note)
                    <h3 class="font-semibold mb-2 text-sm">Reason note</h3>
                    <p class="text-gray-700">{{ $return->reason_note }}</p>
                @endif
                @if ($return->notes)
                    <h3 class="font-semibold mt-3 mb-2 text-sm">Notes</h3>
                    <p class="text-gray-700 whitespace-pre-wrap">{{ $return->notes }}</p>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>
