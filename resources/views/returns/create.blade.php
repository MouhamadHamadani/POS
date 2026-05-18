<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">Return / Refund for {{ $sale->receipt_number }}</h2>
            <a href="{{ route('returns.search') }}" class="text-sm text-gray-600 hover:underline">← Find another sale</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4" x-data="returnForm()">
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="bg-white rounded-lg shadow-card p-5 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
            <div><dt class="text-xs text-gray-500">Sold to</dt><dd class="font-medium">{{ $sale->customer?->name ?? 'Walk-in' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Sold on</dt><dd>{{ $sale->created_at->format('Y-m-d H:i') }}</dd></div>
            <div><dt class="text-xs text-gray-500">Original total</dt><dd class="font-medium">${{ number_format((float) $sale->total_usd, 2) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Status</dt><dd>{{ ucfirst(str_replace('_', ' ', $sale->status)) }}</dd></div>
        </div>

        <form method="POST" action="{{ route('returns.store', $sale) }}" class="space-y-4">
            @csrf

            <div class="bg-white rounded-lg shadow-card p-5">
                <h3 class="font-semibold text-sm mb-3">Items to return</h3>
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="p-2 text-left">Product</th>
                            <th class="p-2 text-right">Sold</th>
                            <th class="p-2 text-right">Already returned</th>
                            <th class="p-2 text-right">Remaining</th>
                            <th class="p-2 text-right">Unit price</th>
                            <th class="p-2 text-right">Return qty</th>
                            <th class="p-2 text-center">Restock?</th>
                            <th class="p-2 text-right">Refund</th>
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
                                <div class="font-medium">{{ $item->product_name }}</div>
                                <div class="text-xs text-gray-500">{{ $item->product?->sku ?? $item->product_sku }}</div>
                            </td>
                            <td class="p-2 text-right">{{ rtrim(rtrim(number_format((float) $item->qty, 4, '.', ''), '0'), '.') }}</td>
                            <td class="p-2 text-right text-gray-500">{{ rtrim(rtrim(number_format((float) $item->returned_qty, 4, '.', ''), '0'), '.') }}</td>
                            <td class="p-2 text-right font-medium">{{ rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.') }}</td>
                            <td class="p-2 text-right text-xs">${{ number_format($perUnit, 4) }}</td>
                            <td class="p-2 text-right">
                                <input type="number" step="0.0001" min="0" max="{{ $remaining }}" value="0"
                                       name="items[{{ $idx }}][qty_returned]"
                                       x-model.number="lines[{{ $idx }}].qty"
                                       @input="recompute({{ $idx }}, {{ $perUnit }})"
                                       :disabled="{{ $remaining <= 0 ? 'true' : 'false' }}"
                                       class="w-20 text-right border-gray-300 rounded text-sm" />
                            </td>
                            <td class="p-2 text-center">
                                <input type="checkbox" name="items[{{ $idx }}][restock]" value="1" checked
                                       x-model="lines[{{ $idx }}].restock" />
                            </td>
                            <td class="p-2 text-right font-medium">$<span x-text="(lines[{{ $idx }}].refund || 0).toFixed(2)"></span></td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot class="border-t-2 text-sm">
                        <tr><td colspan="7" class="p-2 text-right font-bold">Refund total</td>
                            <td class="p-2 text-right font-bold text-brand-700">$<span x-text="refundTotal().toFixed(2)"></span></td></tr>
                    </tfoot>
                </table>
            </div>

            <div class="bg-white rounded-lg shadow-card p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Reason *</label>
                    <select name="reason" required class="w-full border-gray-300 rounded text-sm">
                        <option value="defective">Defective</option>
                        <option value="wrong_item">Wrong item</option>
                        <option value="customer_preference">Customer changed mind</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Refund method *</label>
                    <select name="refund_method" required class="w-full border-gray-300 rounded text-sm">
                        <option value="cash_usd">Cash (USD) — give cash back</option>
                        <option value="cash_lbp">Cash (LBP) — give LBP equivalent</option>
                        @if ($sale->customer_id)
                            <option value="account">Credit to customer account</option>
                        @endif
                        <option value="exchange">Exchange — ring up a new sale separately</option>
                    </select>
                    @unless ($sale->customer_id)
                        <div class="text-xs text-gray-500 mt-1">Account credit requires a customer on the original sale.</div>
                    @endunless
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Reason note</label>
                    <input type="text" name="reason_note" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full border-gray-300 rounded text-sm"></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('returns.index') }}" class="px-4 py-2 bg-gray-100 rounded text-sm">Cancel</a>
                <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800"
                        :disabled="refundTotal() <= 0">
                    Process Return
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
