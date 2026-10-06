<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">
                <x-ltr>{{ $po->po_number }}</x-ltr>
                @php
                    $color = match($po->status) {
                        'draft' => 'bg-gray-100 text-gray-700',
                        'sent' => 'bg-brand-100 text-brand-700',
                        'partial' => 'bg-orange-100 text-orange-700',
                        'received' => 'bg-green-100 text-green-700',
                        'closed' => 'bg-brand-100 text-brand-700',
                        'cancelled' => 'bg-red-100 text-red-700',
                        default => 'bg-gray-100 text-gray-700',
                    };
                @endphp
                <span class="ms-2 text-xs px-2 py-0.5 rounded {{ $color }}">{{ $po->statusLabel() }}</span>
            </h2>
            <div class="flex gap-2">
                @if (in_array($po->status, ['draft','sent']))
                    <a href="{{ route('purchases.edit', $po) }}" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">{{ __('common.edit') }}</a>
                @endif
                <a href="{{ route('purchases.index') }}" class="px-3 py-2 text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('common.back') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>@endif

        <div class="bg-white rounded-lg shadow-card p-6 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><dt class="text-xs text-gray-500">{{ __('purchases.supplier') }}</dt><dd class="font-medium">{{ $po->supplier->name }}</dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('purchases.created_by') }}</dt><dd>{{ $po->user?->name }}</dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('purchases.created') }}</dt><dd><x-ltr>{{ $po->created_at->format('Y-m-d') }}</x-ltr></dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('suppliers.expected') }}</dt><dd>@if ($po->expected_at)<x-ltr>{{ $po->expected_at->format('Y-m-d') }}</x-ltr>@else — @endif</dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('purchases.subtotal') }}</dt><dd><x-ltr>${{ number_format((float) $po->subtotal_usd, 2) }}</x-ltr></dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('purchases.tax') }}</dt><dd><x-ltr>${{ number_format((float) $po->tax_amount_usd, 2) }}</x-ltr></dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('purchases.shipping') }}</dt><dd><x-ltr>${{ number_format((float) $po->shipping_usd, 2) }}</x-ltr></dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('dashboard.total') }}</dt><dd class="font-bold text-brand-700"><x-ltr>${{ number_format((float) $po->total_usd, 2) }}</x-ltr></dd></div>
        </div>

        @if (in_array($po->status, ['draft','sent']))
            <div class="bg-white rounded-lg shadow-card p-4 flex gap-2 items-center text-sm">
                <span class="text-gray-600">{{ __('purchases.actions_label') }}</span>
                @if ($po->status === 'draft')
                    <form method="POST" action="{{ route('purchases.transition', $po) }}">
                        @csrf <input type="hidden" name="to" value="sent">
                        <button class="px-3 py-1.5 bg-brand-700 text-white rounded text-xs">{{ __('purchases.mark_sent') }}</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('purchases.transition', $po) }}">
                    @csrf <input type="hidden" name="to" value="cancelled">
                    <button class="px-3 py-1.5 bg-red-100 text-red-700 rounded text-xs hover:bg-red-200" onclick="return confirm(@js(__('purchases.confirm_cancel')))">{{ __('purchases.cancel_po') }}</button>
                </form>
            </div>
        @endif

        @if (in_array($po->status, ['received','partial']))
            <div class="bg-white rounded-lg shadow-card p-4 flex gap-2 items-center text-sm">
                <form method="POST" action="{{ route('purchases.transition', $po) }}">
                    @csrf <input type="hidden" name="to" value="closed">
                    <button class="px-3 py-1.5 bg-success text-white rounded text-xs">{{ __('purchases.close_po') }}</button>
                </form>
            </div>
        @endif

        <div class="bg-white rounded-lg shadow-card p-6">
            <h3 class="font-semibold mb-3 text-sm">{{ __('purchases.items') }}</h3>
            <table class="min-w-full text-sm">
                <thead class="text-xs uppercase text-gray-500 border-b">
                    <tr>
                        <th class="text-start py-2">{{ __('dashboard.product') }}</th>
                        <th class="text-end py-2">{{ __('purchases.ordered') }}</th>
                        <th class="text-end py-2">{{ __('purchases.received') }}</th>
                        <th class="text-end py-2">{{ __('purchases.cost') }}</th>
                        <th class="text-end py-2">{{ __('purchases.tax') }}</th>
                        <th class="text-end py-2">{{ __('purchases.line_total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @foreach ($po->items as $it)
                    <tr>
                        <td class="py-2">
                            {{ $it->product?->localName() ?? $it->product_name }}
                            <div class="text-xs text-gray-500"><x-ltr>{{ $it->product?->sku ?? '' }}</x-ltr> · {{ __('purchases.in_stock') }}: <x-ltr>{{ rtrim(rtrim(number_format((float) ($it->product?->stock_qty ?? 0), 4, '.', ''), '0'), '.') }}</x-ltr></div>
                        </td>
                        <td class="py-2 text-end"><x-ltr>{{ rtrim(rtrim(number_format((float) $it->qty_ordered, 4, '.', ''), '0'), '.') }}</x-ltr></td>
                        <td class="py-2 text-end {{ $it->qty_received >= $it->qty_ordered ? 'text-success' : ($it->qty_received > 0 ? 'text-warning' : 'text-gray-400') }}">
                            <x-ltr>{{ rtrim(rtrim(number_format((float) $it->qty_received, 4, '.', ''), '0'), '.') }}</x-ltr>
                        </td>
                        <td class="py-2 text-end"><x-ltr>${{ number_format((float) $it->cost_usd, 4) }}</x-ltr></td>
                        <td class="py-2 text-end"><x-ltr>{{ number_format((float) $it->tax_rate * 100, 2) }}%</x-ltr></td>
                        <td class="py-2 text-end"><x-ltr>${{ number_format((float) $it->line_total_usd, 2) }}</x-ltr></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if (in_array($po->status, ['draft','sent','partial']))
            <div class="bg-white rounded-lg shadow-card p-6">
                <h3 class="font-semibold mb-3 text-sm">{{ __('purchases.receive_goods') }}</h3>
                <form method="POST" action="{{ route('purchases.receive', $po) }}">
                    @csrf
                    <table class="min-w-full text-sm">
                        <thead class="text-xs uppercase text-gray-500 border-b">
                            <tr>
                                <th class="text-start py-2">{{ __('dashboard.product') }}</th>
                                <th class="text-end py-2">{{ __('purchases.remaining') }}</th>
                                <th class="text-end py-2">{{ __('purchases.receive_qty') }}</th>
                                <th class="text-end py-2">{{ __('purchases.cost_override') }}</th>
                                <th class="text-end py-2">{{ __('purchases.batch') }}</th>
                                <th class="text-end py-2">{{ __('purchases.expiry') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                        @foreach ($po->items as $idx => $it)
                            @php $remaining = (float) $it->qty_ordered - (float) $it->qty_received; @endphp
                            @if ($remaining > 0)
                            <tr>
                                <td class="py-2">{{ $it->product?->localName() ?? $it->product_name }}</td>
                                <td class="py-2 text-end"><x-ltr>{{ rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.') }}</x-ltr></td>
                                <td class="py-2 text-end">
                                    <input type="hidden" name="receipts[{{ $idx }}][item_id]" value="{{ $it->id }}">
                                    <input type="number" step="0.0001" min="0" max="{{ $remaining }}" value="0" dir="ltr"
                                           name="receipts[{{ $idx }}][qty]" class="w-24 text-end border-gray-300 rounded text-sm" />
                                </td>
                                <td class="py-2 text-end">
                                    <input type="number" step="0.01" min="0" placeholder="{{ number_format((float) $it->cost_usd, 4) }}" dir="ltr"
                                           name="receipts[{{ $idx }}][cost_usd]" class="w-24 text-end border-gray-300 rounded text-sm" />
                                </td>
                                <td class="py-2 text-end">
                                    <input type="text" name="receipts[{{ $idx }}][batch]" dir="ltr" class="w-24 text-end border-gray-300 rounded text-sm" />
                                </td>
                                <td class="py-2 text-end">
                                    <input type="date" name="receipts[{{ $idx }}][expiry]" dir="ltr" class="border-gray-300 rounded text-sm" />
                                </td>
                            </tr>
                            @endif
                        @endforeach
                        </tbody>
                    </table>
                    <div class="text-end mt-4">
                        <button class="px-4 py-2 bg-success text-white rounded text-sm hover:bg-green-700">{{ __('purchases.receive_into_stock') }}</button>
                    </div>
                </form>
            </div>
        @endif

        @if ($po->notes)
            <div class="bg-white rounded-lg shadow-card p-4">
                <h3 class="font-semibold mb-2 text-sm">{{ __('common.notes') }}</h3>
                <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $po->notes }}</p>
            </div>
        @endif
    </div>
</x-app-layout>
