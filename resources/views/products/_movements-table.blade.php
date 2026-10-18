{{-- $rows: InventoryAdjustment rows decorated by StockMovementService (ref_label / ref_url).
     $showProduct: false on the product's own tab, where the product is already known. --}}
@php
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') ?: '0';
    $showProduct = $showProduct ?? true;
@endphp
<div class="bg-white rounded-lg shadow-sm overflow-x-auto">
    <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
            <tr>
                <th class="p-3 text-start">{{ __('stock.cols.date') }}</th>
                @if ($showProduct)<th class="p-3 text-start">{{ __('stock.cols.product') }}</th>@endif
                <th class="p-3 text-start">{{ __('stock.cols.type') }}</th>
                <th class="p-3 text-end">{{ __('stock.cols.qty_in') }}</th>
                <th class="p-3 text-end">{{ __('stock.cols.qty_out') }}</th>
                <th class="p-3 text-end">{{ __('stock.cols.balance') }}</th>
                <th class="p-3 text-start">{{ __('stock.cols.reference') }}</th>
                <th class="p-3 text-start">{{ __('stock.cols.user') }}</th>
                <th class="p-3 text-start">{{ __('stock.cols.notes') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y">
        @forelse ($rows as $r)
            @php $change = (float) $r->qty_change; @endphp
            <tr>
                <td class="p-3 whitespace-nowrap text-xs text-gray-600"><x-ltr>{{ $r->created_at->format('Y-m-d H:i') }}</x-ltr></td>
                @if ($showProduct)
                    <td class="p-3">
                        @if ($r->product)
                            {{ $r->product->localName() }}
                            @if ($r->product->sku)<div class="text-xs text-gray-400"><x-ltr>{{ $r->product->sku }}</x-ltr></div>@endif
                        @else
                            —
                        @endif
                    </td>
                @endif
                <td class="p-3"><span class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-700">{{ \App\Support\EnumLabel::get('stock_movement_type', $r->type) }}</span></td>
                <td class="p-3 text-end text-green-700">@if ($change > 0)<x-ltr>+{{ $qty($change) }}</x-ltr>@endif</td>
                <td class="p-3 text-end text-red-700">@if ($change < 0)<x-ltr>−{{ $qty(-$change) }}</x-ltr>@endif</td>
                <td class="p-3 text-end font-medium"><x-ltr>{{ $qty($r->qty_after) }}</x-ltr></td>
                <td class="p-3 text-xs">
                    @if ($r->ref_url)
                        <a href="{{ $r->ref_url }}" class="text-brand-700 hover:underline"><x-ltr>{{ $r->ref_label }}</x-ltr></a>
                    @else
                        <x-ltr>{{ $r->ref_label }}</x-ltr>
                    @endif
                </td>
                <td class="p-3 text-xs text-gray-600">{{ $r->user?->name }}</td>
                <td class="p-3 text-xs text-gray-600" dir="auto">{{ $r->reasonLabel() }}{{ $r->notes ? ' · '.$r->notes : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ $showProduct ? 9 : 8 }}" class="p-10 text-center text-gray-500">{{ __('stock.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
