<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('Returns & Refunds') }}</h2>
            <a href="{{ route('returns.search') }}" class="px-3 py-2 text-sm bg-brand-700 text-white rounded hover:bg-brand-800">+ New Return</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif

        <div class="bg-white rounded-lg shadow-card p-4">
            <form method="GET" class="flex gap-2 items-end text-sm">
                <div>
                    <label class="block text-xs text-gray-500">Status</label>
                    <select name="status" class="border-gray-300 rounded text-sm">
                        <option value="">All</option>
                        @foreach (['pending','approved','completed','rejected'] as $s)
                            <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="px-3 py-1.5 bg-gray-800 text-white rounded text-xs">Filter</button>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-card overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-left">Return #</th>
                        <th class="p-3 text-left">Original Sale</th>
                        <th class="p-3 text-left">Reason</th>
                        <th class="p-3 text-left">Method</th>
                        <th class="p-3 text-right">Amount</th>
                        <th class="p-3 text-center">Status</th>
                        <th class="p-3 text-right">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($returns as $r)
                    @php
                        $statusClass = match($r->status) {
                            'pending' => 'bg-orange-100 text-orange-700',
                            'approved' => 'bg-blue-100 text-blue-700',
                            'completed' => 'bg-green-100 text-green-700',
                            'rejected' => 'bg-red-100 text-red-700',
                            default => 'bg-gray-100 text-gray-700',
                        };
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="p-3"><a href="{{ route('returns.show', $r) }}" class="font-mono text-xs text-brand-700 hover:underline">{{ $r->return_number }}</a></td>
                        <td class="p-3 font-mono text-xs text-gray-600">{{ $r->sale?->receipt_number ?? '—' }}</td>
                        <td class="p-3 text-xs">{{ ucfirst(str_replace('_', ' ', $r->reason)) }}</td>
                        <td class="p-3 text-xs">{{ str_replace('_', ' ', $r->refund_method) }}</td>
                        <td class="p-3 text-right font-medium">${{ number_format((float) $r->refund_amount_usd, 2) }}</td>
                        <td class="p-3 text-center"><span class="text-xs px-2 py-0.5 rounded {{ $statusClass }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="p-3 text-right text-xs text-gray-500">{{ $r->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-10 text-center text-gray-500">No returns yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $returns->links() }}
    </div>
</x-app-layout>
