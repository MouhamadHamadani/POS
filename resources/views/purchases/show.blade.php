<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">
                {{ $po->po_number }}
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
                <span class="ml-2 text-xs px-2 py-0.5 rounded {{ $color }}">{{ ucfirst($po->status) }}</span>
            </h2>
            <div class="flex gap-2">
                @if (in_array($po->status, ['draft','sent']))
                    <a href="{{ route('purchases.edit', $po) }}" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">Edit</a>
                @endif
                <a href="{{ route('purchases.index') }}" class="px-3 py-2 text-sm text-gray-600 hover:underline">← Back</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>@endif

        <div class="bg-white rounded-lg shadow-card p-6 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><dt class="text-xs text-gray-500">Supplier</dt><dd class="font-medium">{{ $po->supplier->name }}</dd></div>
            <div><dt class="text-xs text-gray-500">Created by</dt><dd>{{ $po->user?->name }}</dd></div>
            <div><dt class="text-xs text-gray-500">Created</dt><dd>{{ $po->created_at->format('Y-m-d') }}</dd></div>
            <div><dt class="text-xs text-gray-500">Expected</dt><dd>{{ $po->expected_at?->format('Y-m-d') ?? '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Subtotal</dt><dd>${{ number_format((float) $po->subtotal_usd, 2) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Tax</dt><dd>${{ number_format((float) $po->tax_amount_usd, 2) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Shipping</dt><dd>${{ number_format((float) $po->shipping_usd, 2) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Total</dt><dd class="font-bold text-brand-700">${{ number_format((float) $po->total_usd, 2) }}</dd></div>
        </div>

        @if (in_array($po->status, ['draft','sent']))
            <div class="bg-white rounded-lg shadow-card p-4 flex gap-2 items-center text-sm">
                <span class="text-gray-600">Actions:</span>
                @if ($po->status === 'draft')
                    <form method="POST" action="{{ route('purchases.transition', $po) }}">
                        @csrf <input type="hidden" name="to" value="sent">
                        <button class="px-3 py-1.5 bg-brand-700 text-white rounded text-xs">Mark as Sent</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('purchases.transition', $po) }}">
                    @csrf <input type="hidden" name="to" value="cancelled">
                    <button class="px-3 py-1.5 bg-red-100 text-red-700 rounded text-xs hover:bg-red-200" onclick="return confirm('Cancel this PO?')">Cancel PO</button>
                </form>
            </div>
        @endif

        @if (in_array($po->status, ['received','partial']))
            <div class="bg-white rounded-lg shadow-card p-4 flex gap-2 items-center text-sm">
                <form method="POST" action="{{ route('purchases.transition', $po) }}">
                    @csrf <input type="hidden" name="to" value="closed">
                    <button class="px-3 py-1.5 bg-success text-white rounded text-xs">Close PO</button>
                </form>
            </div>
        @endif

        <div class="bg-white rounded-lg shadow-card p-6">
            <h3 class="font-semibold mb-3 text-sm">Items</h3>
            <table class="min-w-full text-sm">
                <thead class="text-xs uppercase text-gray-500 border-b">
                    <tr>
                        <th class="text-left py-2">Product</th>
                        <th class="text-right py-2">Ordered</th>
                        <th class="text-right py-2">Received</th>
                        <th class="text-right py-2">Cost</th>
                        <th class="text-right py-2">Tax</th>
                        <th class="text-right py-2">Line total</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @foreach ($po->items as $it)
                    <tr>
                        <td class="py-2">
                            {{ $it->product_name }}
                            <div class="text-xs text-gray-500">{{ $it->product?->sku ?? '' }} · in stock: {{ rtrim(rtrim(number_format((float) ($it->product?->stock_qty ?? 0), 4, '.', ''), '0'), '.') }}</div>
                        </td>
                        <td class="py-2 text-right">{{ rtrim(rtrim(number_format((float) $it->qty_ordered, 4, '.', ''), '0'), '.') }}</td>
                        <td class="py-2 text-right {{ $it->qty_received >= $it->qty_ordered ? 'text-success' : ($it->qty_received > 0 ? 'text-warning' : 'text-gray-400') }}">
                            {{ rtrim(rtrim(number_format((float) $it->qty_received, 4, '.', ''), '0'), '.') }}
                        </td>
                        <td class="py-2 text-right">${{ number_format((float) $it->cost_usd, 4) }}</td>
                        <td class="py-2 text-right">{{ number_format((float) $it->tax_rate * 100, 2) }}%</td>
                        <td class="py-2 text-right">${{ number_format((float) $it->line_total_usd, 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if (in_array($po->status, ['draft','sent','partial']))
            <div class="bg-white rounded-lg shadow-card p-6">
                <h3 class="font-semibold mb-3 text-sm">Receive goods</h3>
                <form method="POST" action="{{ route('purchases.receive', $po) }}">
                    @csrf
                    <table class="min-w-full text-sm">
                        <thead class="text-xs uppercase text-gray-500 border-b">
                            <tr>
                                <th class="text-left py-2">Product</th>
                                <th class="text-right py-2">Remaining</th>
                                <th class="text-right py-2">Receive qty</th>
                                <th class="text-right py-2">Cost override</th>
                                <th class="text-right py-2">Batch</th>
                                <th class="text-right py-2">Expiry</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                        @foreach ($po->items as $idx => $it)
                            @php $remaining = (float) $it->qty_ordered - (float) $it->qty_received; @endphp
                            @if ($remaining > 0)
                            <tr>
                                <td class="py-2">{{ $it->product_name }}</td>
                                <td class="py-2 text-right">{{ rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.') }}</td>
                                <td class="py-2 text-right">
                                    <input type="hidden" name="receipts[{{ $idx }}][item_id]" value="{{ $it->id }}">
                                    <input type="number" step="0.0001" min="0" max="{{ $remaining }}" value="0"
                                           name="receipts[{{ $idx }}][qty]" class="w-24 text-right border-gray-300 rounded text-sm" />
                                </td>
                                <td class="py-2 text-right">
                                    <input type="number" step="0.01" min="0" placeholder="{{ number_format((float) $it->cost_usd, 4) }}"
                                           name="receipts[{{ $idx }}][cost_usd]" class="w-24 text-right border-gray-300 rounded text-sm" />
                                </td>
                                <td class="py-2 text-right">
                                    <input type="text" name="receipts[{{ $idx }}][batch]" class="w-24 text-right border-gray-300 rounded text-sm" />
                                </td>
                                <td class="py-2 text-right">
                                    <input type="date" name="receipts[{{ $idx }}][expiry]" class="border-gray-300 rounded text-sm" />
                                </td>
                            </tr>
                            @endif
                        @endforeach
                        </tbody>
                    </table>
                    <div class="text-right mt-4">
                        <button class="px-4 py-2 bg-success text-white rounded text-sm hover:bg-green-700">Receive into Stock</button>
                    </div>
                </form>
            </div>
        @endif

        @if ($po->notes)
            <div class="bg-white rounded-lg shadow-card p-4">
                <h3 class="font-semibold mb-2 text-sm">Notes</h3>
                <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $po->notes }}</p>
            </div>
        @endif
    </div>
</x-app-layout>
