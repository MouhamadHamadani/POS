@php
    $rtl = app()->getLocale() === 'ar';
    $money = fn ($v) => '$'.number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') ?: '0';
    $rate = fn ($r) => rtrim(rtrim(number_format((float) $r, 2, '.', ''), '0'), '.');
    $fx = fn ($r) => rtrim(rtrim(number_format((float) $r, 2), '0'), '.');
    $vatRates = collect($inv['vat_rates'])->map(fn ($r) => $rate($r).'%')->implode(', ');
    $hasReturned = $sale->items->contains(fn ($i) => (float) $i->returned_qty > 0);
    $tier = $sale->price_tier ?: 'retail';
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">
                <x-ltr>{{ $sale->receipt_number }}</x-ltr>
                <x-sale-status :sale="$sale" class="ms-2" />
            </h2>
            {{-- Back to the list with the filters it was opened from (they ride along in this page's query string). --}}
            <a href="{{ route('sales.index', request()->query()) }}" class="text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('sales.back_to_list') }}</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4" x-data="salesReprint(@js($a4Silent))">

        <div class="bg-white rounded-lg shadow-card p-4 flex flex-wrap items-center gap-2">
            @if ($sale->hasReceipt())
                @foreach ($formats as $fmt)
                    <button type="button" @click="print({{ $sale->id }}, '{{ $fmt }}', 'detail')"
                            class="px-3 py-2 text-sm rounded {{ $ask || $loop->first ? 'bg-brand-700 text-white hover:bg-brand-800' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">{{ $fmt === 'a4' ? __('sales.reprint_a4') : __('sales.reprint_receipt') }}</button>
                @endforeach
                <span class="text-xs text-gray-500">{{ __('sales.reprint_hint') }}</span>
            @else
                <span class="text-sm text-amber-700">{{ __('sales.held_no_receipt') }}</span>
            @endif
            @if ($canReturn)
                <a href="{{ route('returns.create', $sale->id) }}" class="ms-auto px-3 py-2 text-sm rounded border border-gray-300 text-gray-700 hover:bg-gray-50">{{ __('sales.start_return') }}</a>
            @endif
        </div>

        <div class="bg-white rounded-lg shadow-card p-6 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
            <div><dt class="text-xs text-gray-500">{{ __('receipt.date') }}</dt><dd><x-ltr>{{ $sale->created_at->format('Y-m-d H:i') }}</x-ltr></dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('receipt.cashier') }}</dt><dd>{{ $sale->user?->name ?? '—' }}</dd></div>
            <div>
                <dt class="text-xs text-gray-500">{{ __('receipt.customer') }}</dt>
                <dd>
                    {{ $sale->customer?->name ?? __('returns.walk_in') }}
                    @if ($sale->customer?->phone)<div class="text-xs text-gray-500"><x-ltr>{{ $sale->customer->phone }}</x-ltr></div>@endif
                </dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">{{ __('sales.shift') }}</dt>
                <dd>
                    @if ($sale->shift)
                        <x-ltr>#{{ $sale->shift->id }}</x-ltr>
                        <div class="text-xs text-gray-500"><x-ltr>{{ $sale->shift->opened_at?->format('Y-m-d H:i') }}</x-ltr></div>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div><dt class="text-xs text-gray-500">{{ __('pos.receipt.price_list') }}</dt><dd>{{ __("pos.tier.$tier") }}</dd></div>
            {{-- Shown with the feature, and for any online order even once it is off: what was stored stays readable. --}}
            @if (feature('sales_channels') || $sale->isOnline())
                <div>
                    <dt class="text-xs text-gray-500">{{ __('sales.channel') }}</dt>
                    <dd>{{ $sale->channelLabel() }}@if ($sale->channel_source) <span class="text-gray-500">· {{ $sale->channelSourceLabel() }}</span>@endif</dd>
                </div>
            @endif
            <div><dt class="text-xs text-gray-500">{{ __('receipt.payment') }}</dt><dd>{{ $sale->paymentMethodLabel() }}</dd></div>
            @if ((float) $sale->exchange_rate > 0)
                <div><dt class="text-xs text-gray-500">{{ __('invoice.exchange_rate') }}</dt><dd><x-ltr>1 USD = {{ $fx($sale->exchange_rate) }} LBP</x-ltr></dd></div>
            @endif
            @if ($sale->voided_at)
                <div><dt class="text-xs text-gray-500">{{ __('enums.sale_status.voided') }}</dt><dd class="text-red-700">{{ __('sales.voided_on', ['date' => $sale->voided_at->format('Y-m-d H:i')]) }}</dd></div>
            @endif
        </div>

        <div class="bg-white rounded-lg shadow-card overflow-x-auto">
            <div class="px-4 pt-4 text-sm font-semibold">{{ __('sales.items') }}</div>
            <table class="min-w-full text-sm mt-2">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-start">{{ __('invoice.item') }}</th>
                        <th class="p-3 text-end">{{ __('invoice.qty') }}</th>
                        <th class="p-3 text-end">{{ __('invoice.unit_price') }}</th>
                        @if ($inv['has_line_discount'])<th class="p-3 text-end">{{ __('invoice.discount') }}</th>@endif
                        @if ($hasReturned)<th class="p-3 text-end">{{ __('returns.already_returned') }}</th>@endif
                        <th class="p-3 text-end">{{ __('invoice.line_total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @foreach ($sale->items as $item)
                    @php $product = $item->product; @endphp
                    <tr>
                        <td class="p-3">
                            {{ $rtl && $product?->name_ar ? $product->name_ar : $item->product_name }}
                            @if ($item->product_sku)<div class="text-xs text-gray-500"><x-ltr>{{ $item->product_sku }}</x-ltr></div>@endif
                        </td>
                        <td class="p-3 text-end whitespace-nowrap"><x-ltr>{{ $qty($item->qty) }}</x-ltr>@if ($product?->unit) {{ \App\Support\EnumLabel::get('unit', $product->unit) }}@endif</td>
                        <td class="p-3 text-end"><x-ltr>{{ $money($item->unit_price_usd) }}</x-ltr></td>
                        @if ($inv['has_line_discount'])
                            <td class="p-3 text-end text-gray-500"><x-ltr>{{ (float) $item->discount_amount_usd > 0 ? '-'.$money($item->discount_amount_usd) : '' }}</x-ltr></td>
                        @endif
                        @if ($hasReturned)
                            <td class="p-3 text-end text-gray-500"><x-ltr>{{ (float) $item->returned_qty > 0 ? $qty($item->returned_qty) : '' }}</x-ltr></td>
                        @endif
                        <td class="p-3 text-end font-medium"><x-ltr>{{ $money($item->line_total_usd) }}</x-ltr></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="bg-white rounded-lg shadow-card p-6 md:ms-auto md:max-w-md text-sm">
            <table class="w-full">
                <tr><td class="py-0.5">{{ __('receipt.subtotal') }}</td><td class="text-end"><x-ltr>{{ $money($sale->subtotal_usd) }}</x-ltr></td></tr>
                @if ($inv['cart_discount'] > 0)
                    @if ($inv['item_discounts'] > 0.00005)
                        <tr><td class="py-0.5">{{ __('invoice.item_discounts') }}</td><td class="text-end"><x-ltr>-{{ $money($inv['item_discounts']) }}</x-ltr></td></tr>
                    @endif
                    <tr><td class="py-0.5">{{ __('invoice.cart_discount') }}</td><td class="text-end"><x-ltr>-{{ $money($inv['cart_discount']) }}</x-ltr></td></tr>
                @elseif ($inv['discount'] > 0)
                    <tr><td class="py-0.5">{{ __('receipt.discount') }}</td><td class="text-end"><x-ltr>-{{ $money($inv['discount']) }}</x-ltr></td></tr>
                @endif
                @if ((float) $sale->tax_amount_usd > 0)
                    <tr><td class="py-0.5">{{ $vatRates !== '' ? __('invoice.vat_rate', ['rate' => $vatRates]) : __('receipt.vat') }}</td><td class="text-end"><x-ltr>{{ $money($sale->tax_amount_usd) }}</x-ltr></td></tr>
                @endif
                <tr class="font-bold text-brand-700 border-t"><td class="pt-1.5">{{ __('invoice.total_usd') }}</td><td class="pt-1.5 text-end"><x-ltr>{{ $money($sale->total_usd) }}</x-ltr></td></tr>
                @if ((float) $sale->total_lbp > 0)
                    <tr><td class="py-0.5">{{ __('invoice.total_lbp') }}</td><td class="text-end"><x-ltr>{{ number_format((float) $sale->total_lbp) }}</x-ltr></td></tr>
                @endif

                <tr class="border-t"><td class="pt-1.5">{{ __('receipt.payment') }}</td><td class="pt-1.5 text-end">{{ $sale->paymentMethodLabel() }}</td></tr>
                @if ((float) $sale->amount_tendered_usd > 0)
                    <tr><td class="py-0.5">{{ __('receipt.cash_usd_tendered') }}</td><td class="text-end"><x-ltr>{{ $money($sale->amount_tendered_usd) }}</x-ltr></td></tr>
                @endif
                @if ((float) $sale->amount_tendered_lbp > 0)
                    <tr><td class="py-0.5">{{ __('receipt.cash_lbp_tendered') }}</td><td class="text-end"><x-ltr>{{ number_format((float) $sale->amount_tendered_lbp) }}</x-ltr></td></tr>
                @endif
                @if ((float) $sale->amount_card_usd > 0)
                    <tr>
                        <td class="py-0.5">{{ __('receipt.card') }}@if ($sale->card_type || $sale->card_reference)<div class="text-xs text-gray-500">{{ $sale->card_type }}@if ($sale->card_reference) <x-ltr>{{ __('sales.card_reference', ['ref' => $sale->card_reference]) }}</x-ltr>@endif</div>@endif</td>
                        <td class="text-end"><x-ltr>{{ $money($sale->amount_card_usd) }}</x-ltr></td>
                    </tr>
                @endif
                @if ((float) $sale->amount_credit_usd > 0)
                    <tr><td class="py-0.5">{{ __('receipt.on_account') }}</td><td class="text-end"><x-ltr>{{ $money($sale->amount_credit_usd) }}</x-ltr></td></tr>
                @endif
                @if ((float) $sale->change_usd > 0)
                    <tr class="font-medium"><td class="py-0.5">{{ __('receipt.change_usd') }}</td><td class="text-end"><x-ltr>{{ $money($sale->change_usd) }}</x-ltr></td></tr>
                @endif
                @if ((float) $sale->change_lbp > 0)
                    <tr class="font-medium"><td class="py-0.5">{{ __('receipt.change_lbp') }}</td><td class="text-end"><x-ltr>{{ number_format((float) $sale->change_lbp) }}</x-ltr></td></tr>
                @endif
            </table>
            @if ($sale->loyalty_points_earned > 0 || $sale->loyalty_points_redeemed > 0)
                <div class="mt-2 text-xs text-gray-500 space-y-0.5">
                    @if ($sale->loyalty_points_earned > 0)<div>{{ __('receipt.loyalty_earned', ['count' => $sale->loyalty_points_earned]) }}</div>@endif
                    @if ($sale->loyalty_points_redeemed > 0)<div>{{ __('sales.loyalty_redeemed', ['count' => $sale->loyalty_points_redeemed]) }}</div>@endif
                </div>
            @endif
        </div>

        @if ($sale->notes)
            <div class="bg-white rounded-lg shadow-card p-4 text-sm"><div class="text-xs text-gray-500">{{ __('common.notes') }}</div>{{ $sale->notes }}</div>
        @endif

        @if ($sale->returns->isNotEmpty())
            <div class="bg-white rounded-lg shadow-card overflow-x-auto">
                <div class="px-4 pt-4 text-sm font-semibold">{{ __('sales.linked_returns') }}</div>
                <table class="min-w-full text-sm mt-2">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="p-3 text-start">{{ __('returns.return_number') }}</th>
                            <th class="p-3 text-start">{{ __('receipt.date') }}</th>
                            <th class="p-3 text-end">{{ __('returns.amount') }}</th>
                            <th class="p-3 text-center">{{ __('common.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                    @foreach ($sale->returns as $r)
                        <tr>
                            <td class="p-3 font-mono text-xs">
                                @feature('returns')
                                    <a href="{{ route('returns.show', $r->id) }}" class="text-brand-700 hover:underline"><x-ltr>{{ $r->return_number }}</x-ltr></a>
                                @else
                                    <x-ltr>{{ $r->return_number }}</x-ltr>
                                @endfeature
                            </td>
                            <td class="p-3 text-xs text-gray-600"><x-ltr>{{ $r->created_at->format('Y-m-d H:i') }}</x-ltr></td>
                            <td class="p-3 text-end font-medium"><x-ltr>{{ $money($r->refund_amount_usd) }}</x-ltr></td>
                            <td class="p-3 text-center text-xs">{{ $r->statusLabel() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @include('sales._reprint')
</x-app-layout>
