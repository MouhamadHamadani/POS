<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('Purchase Orders') }}</h2>
            <a href="{{ route('purchases.create') }}" class="px-3 py-2 text-sm bg-brand-700 text-white rounded hover:bg-brand-800">+ New PO</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif

        <div class="bg-white rounded-lg shadow-card p-4">
            <form method="GET" class="flex flex-wrap gap-2 items-end text-sm">
                <div>
                    <label class="block text-xs text-gray-500">Status</label>
                    <select name="status" class="border-gray-300 rounded text-sm">
                        <option value="">All</option>
                        @foreach (['draft','sent','partial','received','closed','cancelled'] as $st)
                            <option value="{{ $st }}" @selected(request('status') === $st)>{{ ucfirst($st) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Supplier</label>
                    <select name="supplier" class="border-gray-300 rounded text-sm">
                        <option value="">All</option>
                        @foreach ($suppliers as $s)
                            <option value="{{ $s->id }}" @selected(request('supplier') == $s->id)>{{ $s->name }}</option>
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
                        <th class="p-3 text-left">PO #</th>
                        <th class="p-3 text-left">Supplier</th>
                        <th class="p-3 text-left">Status</th>
                        <th class="p-3 text-right">Total</th>
                        <th class="p-3 text-right">Expected</th>
                        <th class="p-3 text-right">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($pos as $po)
                    @php
                        $color = match($po->status) {
                            'draft' => 'bg-gray-100 text-gray-700',
                            'sent' => 'bg-blue-100 text-blue-700',
                            'partial' => 'bg-orange-100 text-orange-700',
                            'received' => 'bg-green-100 text-green-700',
                            'closed' => 'bg-brand-100 text-brand-700',
                            'cancelled' => 'bg-red-100 text-red-700',
                            default => 'bg-gray-100 text-gray-700',
                        };
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="p-3"><a href="{{ route('purchases.show', $po) }}" class="font-mono text-xs text-brand-700 hover:underline">{{ $po->po_number }}</a></td>
                        <td class="p-3">{{ $po->supplier?->name ?? '—' }}</td>
                        <td class="p-3"><span class="px-2 py-0.5 rounded text-xs {{ $color }}">{{ ucfirst($po->status) }}</span></td>
                        <td class="p-3 text-right font-medium">${{ number_format((float) $po->total_usd, 2) }}</td>
                        <td class="p-3 text-right text-xs text-gray-500">{{ $po->expected_at?->format('Y-m-d') ?? '—' }}</td>
                        <td class="p-3 text-right text-xs text-gray-500">{{ $po->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-10 text-center text-gray-500">No purchase orders yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $pos->links() }}
    </div>
</x-app-layout>
