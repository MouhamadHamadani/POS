<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('Returns & Refunds') }}</h2>
            <a href="{{ route('returns.search') }}" class="px-3 py-2 text-sm bg-brand-700 text-white rounded hover:bg-brand-800">{{ __('returns.new_return') }}</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>@endif

        <div class="bg-white rounded-lg shadow-card p-4">
            <form method="GET" class="flex gap-2 items-end text-sm">
                <div>
                    <label class="block text-xs text-gray-500">{{ __('common.status') }}</label>
                    <select name="status" class="border-gray-300 rounded text-sm">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach (['pending','approved','completed','rejected'] as $s)
                            <option value="{{ $s }}" @selected(request('status') === $s)>{{ __("enums.return_status.$s") }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="px-3 py-1.5 bg-gray-800 text-white rounded text-xs">{{ __('common.filter') }}</button>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-card overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-start">{{ __('returns.return_number') }}</th>
                        <th class="p-3 text-start">{{ __('returns.original_sale') }}</th>
                        <th class="p-3 text-start">{{ __('returns.reason') }}</th>
                        <th class="p-3 text-start">{{ __('customers.method') }}</th>
                        <th class="p-3 text-end">{{ __('returns.amount') }}</th>
                        <th class="p-3 text-center">{{ __('common.status') }}</th>
                        <th class="p-3 text-end">{{ __('dashboard.when') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($returns as $r)
                    @php
                        $statusClass = match($r->status) {
                            'pending' => 'bg-orange-100 text-orange-700',
                            'approved' => 'bg-brand-100 text-brand-700',
                            'completed' => 'bg-green-100 text-green-700',
                            'rejected' => 'bg-red-100 text-red-700',
                            default => 'bg-gray-100 text-gray-700',
                        };
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="p-3"><a href="{{ route('returns.show', $r) }}" class="font-mono text-xs text-brand-700 hover:underline"><x-ltr>{{ $r->return_number }}</x-ltr></a></td>
                        <td class="p-3 font-mono text-xs text-gray-600">@if ($r->sale?->receipt_number)<x-receipt-link :sale="$r->sale" />@else — @endif</td>
                        <td class="p-3 text-xs">{{ $r->reasonLabel() }}</td>
                        <td class="p-3 text-xs">{{ $r->refundMethodLabel() }}</td>
                        <td class="p-3 text-end font-medium"><x-ltr>${{ number_format((float) $r->refund_amount_usd, 2) }}</x-ltr></td>
                        <td class="p-3 text-center"><span class="text-xs px-2 py-0.5 rounded {{ $statusClass }}">{{ $r->statusLabel() }}</span></td>
                        <td class="p-3 text-end text-xs text-gray-500">{{ $r->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-10 text-center text-gray-500">{{ __('returns.none_yet') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $returns->links() }}
    </div>
</x-app-layout>
