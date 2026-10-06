<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('returns.return_for') }} <x-ltr>{{ $sale->receipt_number }}</x-ltr></h2>
            <a href="{{ route('returns.search') }}" class="text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('returns.find_another') }}</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4" x-data="returnForm()">
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="bg-white rounded-lg shadow-card p-5 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
            <div><dt class="text-xs text-gray-500">{{ __('returns.sold_to') }}</dt><dd class="font-medium">{{ $sale->customer?->name ?? __('returns.walk_in') }}</dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('returns.sold_on') }}</dt><dd><x-ltr>{{ $sale->created_at->format('Y-m-d H:i') }}</x-ltr></dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('returns.original_total') }}</dt><dd class="font-medium"><x-ltr>${{ number_format((float) $sale->total_usd, 2) }}</x-ltr></dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('common.status') }}</dt><dd>{{ $sale->statusLabel() }}</dd></div>
        </div>

        <form method="POST" action="{{ route('returns.store', $sale) }}" class="space-y-4">
            @csrf

            <div class="bg-white rounded-lg shadow-card p-5">
                <h3 class="font-semibold text-sm mb-3">{{ __('returns.items_to_return') }}</h3>
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="p-2 text-start">{{ __('dashboard.product') }}</th>
                            <th class="p-2 text-end">{{ __('returns.sold') }}</th>
                            <th class="p-2 text-end">{{ __('returns.already_returned') }}</th>
                            <th class="p-2 text-end">{{ __('purchases.remaining') }}</th>
                            <th class="p-2 text-end">{{ __('returns.unit_price') }}</th>
                            <th class="p-2 text-end">{{ __('returns.return_qty') }}</th>
                            <th class="p-2 text-center">{{ __('returns.restock') }}</th>
                            <th class="p-2 text-end">{{ __('returns.refund') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                    @foreach ($sale->items as $idx => $item)
                        @php
                            $remaining = (float) $item->qty - (float) $item->returned_qty;
                            $perUnit = (float) $item->qty > 0 ? (float) $item->line_total_usd / (float) $item->qty : 0;
                        @endphp
                        <tr @class(['opacity-50' => $remaining <= 0])>
                            <td class="p-2">
                                <input type="hidden" name="items[{{ $idx }}][sale_item_id]" value="{{ $item->id }}">
                                <div class="font-medium">{{ $item->product?->localName() ?? $item->product_name }}</div>
                                <div class="text-xs text-gray-500"><x-ltr>{{ $item->product?->sku ?? $item->product_sku }}</x-ltr></div>
                            </td>
                            <td class="p-2 text-end"><x-ltr>{{ rtrim(rtrim(number_format((float) $item->qty, 4, '.', ''), '0'), '.') }}</x-ltr></td>
                            <td class="p-2 text-end text-gray-500"><x-ltr>{{ rtrim(rtrim(number_format((float) $item->returned_qty, 4, '.', ''), '0'), '.') }}</x-ltr></td>
                            <td class="p-2 text-end font-medium"><x-ltr>{{ rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.') }}</x-ltr></td>
                            <td class="p-2 text-end text-xs"><x-ltr>${{ number_format($perUnit, 4) }}</x-ltr></td>
                            <td class="p-2 text-end">
                                <input type="number" step="0.0001" min="0" max="{{ $remaining }}" value="0" dir="ltr"
                                       name="items[{{ $idx }}][qty_returned]"
                                       x-model.number="lines[{{ $idx }}].qty"
                                       @input="recompute({{ $idx }}, {{ $perUnit }})"
                                       :disabled="{{ $remaining <= 0 ? 'true' : 'false' }}"
                                       class="w-20 text-end border-gray-300 rounded text-sm" />
                            </td>
                            <td class="p-2 text-center">
                                <input type="checkbox" name="items[{{ $idx }}][restock]" value="1" checked
                                       x-model="lines[{{ $idx }}].restock" />
                            </td>
                            <td class="p-2 text-end font-medium"><x-ltr>$<span x-text="(lines[{{ $idx }}].refund || 0).toFixed(2)"></span></x-ltr></td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot class="border-t-2 text-sm">
                        <tr><td colspan="7" class="p-2 text-end font-bold">{{ __('returns.refund_total') }}</td>
                            <td class="p-2 text-end font-bold text-brand-700"><x-ltr>$<span x-text="refundTotal().toFixed(2)"></span></x-ltr></td></tr>
                    </tfoot>
                </table>
            </div>

            <div class="bg-white rounded-lg shadow-card p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('returns.reason') }} *</label>
                    <select name="reason" required class="w-full border-gray-300 rounded text-sm">
                        @foreach (['defective', 'wrong_item', 'customer_preference', 'other'] as $r)
                            <option value="{{ $r }}">{{ __("enums.return_reason.$r") }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('returns.refund_method') }} *</label>
                    <select name="refund_method" required class="w-full border-gray-300 rounded text-sm">
                        <option value="cash_usd">{{ __('returns.opt_cash_usd') }}</option>
                        <option value="cash_lbp">{{ __('returns.opt_cash_lbp') }}</option>
                        @if ($sale->customer_id)
                            <option value="account">{{ __('returns.opt_account') }}</option>
                        @endif
                        <option value="exchange">{{ __('returns.opt_exchange') }}</option>
                    </select>
                    @unless ($sale->customer_id)
                        <div class="text-xs text-gray-500 mt-1">{{ __('returns.account_needs_customer') }}</div>
                    @endunless
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('returns.reason_note') }}</label>
                    <input type="text" name="reason_note" dir="auto" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('common.notes') }}</label>
                    <textarea name="notes" dir="auto" rows="2" class="w-full border-gray-300 rounded text-sm"></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('returns.index') }}" class="px-4 py-2 bg-gray-100 rounded text-sm">{{ __('common.cancel') }}</a>
                <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800"
                        :disabled="refundTotal() <= 0">
                    {{ __('returns.process') }}
                </button>
            </div>
        </form>
    </div>

    @push('scripts')
    <script>
        function returnForm() {
            return {
                lines: Array.from({ length: {{ $sale->items->count() }} }, () => ({ qty: 0, restock: true, refund: 0 })),
                recompute(idx, perUnit) {
                    this.lines[idx].refund = (this.lines[idx].qty || 0) * perUnit;
                },
                refundTotal() {
                    return this.lines.reduce((sum, l) => sum + (l.refund || 0), 0);
                },
            }
        }
    </script>
    @endpush
</x-app-layout>
