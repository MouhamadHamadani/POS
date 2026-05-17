<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $isEdit ? "Edit PO {$po->po_number}" : 'New Purchase Order' }}</h2>
            <a href="{{ route('purchases.index') }}" class="text-sm text-gray-600 hover:underline">← Back</a>
        </div>
    </x-slot>

    @php
        $itemsForJs = $items->map(fn($i) => [
            'id' => $i->id,
            'product_id' => $i->product_id,
            'product_name' => $i->product_name ?? $i->product?->name,
            'qty_ordered' => (float) $i->qty_ordered,
            'cost_usd' => (float) $i->cost_usd,
            'tax_rate' => (float) $i->tax_rate,
        ])->values();
    @endphp

    <div class="py-6 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8" x-data="poForm()">
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm mb-4">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ $isEdit ? route('purchases.update', $po) : route('purchases.store') }}" class="space-y-4">
            @csrf
            @if ($isEdit) @method('PUT') @endif

            <div class="bg-white rounded-lg shadow-card p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Supplier *</label>
                    <select name="supplier_id" required class="w-full border-gray-300 rounded text-sm">
                        <option value="">— Select —</option>
                        @foreach ($suppliers as $s)
                            <option value="{{ $s->id }}" @selected(old('supplier_id', $po->supplier_id) == $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Expected delivery</label>
                    <input type="date" name="expected_at" value="{{ old('expected_at', $po->expected_at?->format('Y-m-d')) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                    <select name="status" class="w-full border-gray-300 rounded text-sm">
                        <option value="draft" @selected(old('status', $po->status) === 'draft')>Draft</option>
                        <option value="sent" @selected(old('status', $po->status) === 'sent')>Sent to supplier</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Supplier reference</label>
                    <input type="text" name="supplier_reference" value="{{ old('supplier_reference', $po->supplier_reference) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Shipping (USD)</label>
                    <input type="number" step="0.01" min="0" name="shipping_usd" value="{{ old('shipping_usd', $po->shipping_usd ?? 0) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full border-gray-300 rounded text-sm">{{ old('notes', $po->notes) }}</textarea>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-card p-6">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="font-semibold text-sm">Line items</h3>
                    <div class="flex gap-2 items-center">
                        <select x-model.number="picker" class="border-gray-300 rounded text-sm">
                            <option value="">+ Add product…</option>
                            <template x-for="p in products" :key="p.id">
                                <option :value="p.id" x-text="`${p.name}${p.sku ? ' — ' + p.sku : ''}`"></option>
                            </template>
                        </select>
                        <button type="button" @click="addLine()" class="px-3 py-1.5 text-xs bg-brand-700 text-white rounded">Add</button>
                    </div>
                </div>

                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500 border-b">
                        <tr>
                            <th class="text-left py-2">Product</th>
                            <th class="text-right py-2 w-24">Qty</th>
                            <th class="text-right py-2 w-32">Cost (USD)</th>
                            <th class="text-right py-2 w-20">Tax %</th>
                            <th class="text-right py-2 w-32">Line total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <template x-for="(line, idx) in lines" :key="idx">
                            <tr>
                                <td class="py-2">
                                    <input type="hidden" :name="`items[${idx}][id]`" :value="line.id || ''">
                                    <input type="hidden" :name="`items[${idx}][product_id]`" :value="line.product_id">
                                    <div class="font-medium" x-text="line.product_name"></div>
                                </td>
                                <td class="py-2 text-right">
                                    <input type="number" step="0.0001" min="0.0001" :name="`items[${idx}][qty_ordered]`"
                                           x-model.number="line.qty_ordered" class="w-20 text-right border-gray-300 rounded text-sm">
                                </td>
                                <td class="py-2 text-right">
                                    <input type="number" step="0.01" min="0" :name="`items[${idx}][cost_usd]`"
                                           x-model.number="line.cost_usd" class="w-24 text-right border-gray-300 rounded text-sm">
                                </td>
                                <td class="py-2 text-right">
                                    <input type="number" step="0.0001" min="0" :name="`items[${idx}][tax_rate]`"
                                           x-model.number="line.tax_rate" class="w-16 text-right border-gray-300 rounded text-sm">
                                </td>
                                <td class="py-2 text-right font-medium">$<span x-text="lineTotal(line).toFixed(2)"></span></td>
                                <td class="py-2 text-right">
                                    <button type="button" @click="removeLine(idx)" class="text-red-500 text-sm">×</button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="lines.length === 0"><td colspan="6" class="py-6 text-center text-gray-400 text-sm">No lines yet. Pick a product above.</td></tr>
                    </tbody>
                    <tfoot class="border-t-2 text-sm">
                        <tr><td colspan="4" class="py-2 text-right text-gray-500">Subtotal</td><td class="py-2 text-right">$<span x-text="totals().subtotal.toFixed(2)"></span></td><td></td></tr>
                        <tr><td colspan="4" class="py-1 text-right text-gray-500">Tax</td><td class="py-1 text-right">$<span x-text="totals().tax.toFixed(2)"></span></td><td></td></tr>
                        <tr><td colspan="4" class="py-2 text-right font-bold">Total (excl. shipping)</td><td class="py-2 text-right font-bold text-brand-700">$<span x-text="totals().total.toFixed(2)"></span></td><td></td></tr>
                    </tfoot>
                </table>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('purchases.index') }}" class="px-4 py-2 bg-gray-100 rounded text-sm">Cancel</a>
                <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">
                    {{ $isEdit ? 'Save Changes' : 'Create PO' }}
                </button>
            </div>
        </form>
    </div>

    @push('scripts')
    <script>
        function poForm() {
            return {
                products: @json($products),
                lines: @json($itemsForJs),
                picker: '',
                addLine() {
                    const id = Number(this.picker);
                    if (!id) return;
                    const p = this.products.find(x => x.id === id);
                    if (!p) return;
                    if (this.lines.find(l => l.product_id === id)) {
                        alert('Product already in PO. Adjust the qty instead.');
                        return;
                    }
                    this.lines.push({
                        id: null,
                        product_id: p.id,
                        product_name: p.name,
                        qty_ordered: 1,
                        cost_usd: Number(p.cost_usd) || 0,
                        tax_rate: p.tax ? Number(p.tax.rate) : 0,
                    });
                    this.picker = '';
                },
                removeLine(i) { this.lines.splice(i, 1); },
                lineTotal(line) {
                    const sub = Number(line.qty_ordered || 0) * Number(line.cost_usd || 0);
                    return sub * (1 + Number(line.tax_rate || 0));
                },
                totals() {
                    let subtotal = 0, tax = 0;
                    for (const l of this.lines) {
                        const sub = Number(l.qty_ordered || 0) * Number(l.cost_usd || 0);
                        subtotal += sub;
                        tax += sub * Number(l.tax_rate || 0);
                    }
                    return { subtotal, tax, total: subtotal + tax };
                },
            }
        }
    </script>
    @endpush
</x-app-layout>
