<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $isEdit ? __('purchases.edit_po', ['number' => $po->po_number]) : __('purchases.new_po_title') }}</h2>
            <a href="{{ route('purchases.index') }}" class="text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('common.back') }}</a>
        </div>
    </x-slot>

    @php
        $itemsForJs = $items->map(fn($i) => [
            'id' => $i->id,
            'product_id' => $i->product_id,
            'product_name' => $i->product?->localName() ?? $i->product_name,
            'qty_ordered' => (float) $i->qty_ordered,
            'cost_usd' => (float) $i->cost_usd,
            'tax_rate' => (float) $i->tax_rate,
        ])->values();

        $productsForJs = $products->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->localName(),
            'sku' => $p->sku,
            'cost_usd' => $p->cost_usd,
            'tax' => $p->tax ? ['rate' => $p->tax->rate] : null,
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
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('purchases.supplier') }} *</label>
                    <select name="supplier_id" required class="w-full border-gray-300 rounded text-sm">
                        <option value="">{{ __('purchases.select') }}</option>
                        @foreach ($suppliers as $s)
                            <option value="{{ $s->id }}" @selected(old('supplier_id', $po->supplier_id) == $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('purchases.expected_delivery') }}</label>
                    <input type="date" name="expected_at" dir="ltr" value="{{ old('expected_at', $po->expected_at?->format('Y-m-d')) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('common.status') }}</label>
                    <select name="status" class="w-full border-gray-300 rounded text-sm">
                        <option value="draft" @selected(old('status', $po->status) === 'draft')>{{ __('enums.purchase_status.draft') }}</option>
                        <option value="sent" @selected(old('status', $po->status) === 'sent')>{{ __('purchases.sent_to_supplier') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('purchases.supplier_reference') }}</label>
                    <input type="text" name="supplier_reference" dir="ltr" value="{{ old('supplier_reference', $po->supplier_reference) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('purchases.shipping_usd') }}</label>
                    <input type="number" step="0.01" min="0" name="shipping_usd" dir="ltr" value="{{ old('shipping_usd', $po->shipping_usd ?? 0) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('common.notes') }}</label>
                    <textarea name="notes" dir="auto" rows="2" class="w-full border-gray-300 rounded text-sm">{{ old('notes', $po->notes) }}</textarea>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-card p-6">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="font-semibold text-sm">{{ __('purchases.line_items') }}</h3>
                    <div class="flex gap-2 items-center">
                        <select x-model.number="picker" class="border-gray-300 rounded text-sm">
                            <option value="">{{ __('purchases.add_product') }}</option>
                            <template x-for="p in products" :key="p.id">
                                <option :value="p.id" x-text="`${p.name}${p.sku ? ' — ' + p.sku : ''}`"></option>
                            </template>
                        </select>
                        <button type="button" @click="addLine()" class="px-3 py-1.5 text-xs bg-brand-700 text-white rounded">{{ __('common.add') }}</button>
                    </div>
                </div>

                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500 border-b">
                        <tr>
                            <th class="text-start py-2">{{ __('dashboard.product') }}</th>
                            <th class="text-end py-2 w-24">{{ __('purchases.qty') }}</th>
                            <th class="text-end py-2 w-32">{{ __('purchases.cost_usd') }}</th>
                            <th class="text-end py-2 w-20">{{ __('purchases.tax_pct') }}</th>
                            <th class="text-end py-2 w-32">{{ __('purchases.line_total') }}</th>
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
                                <td class="py-2 text-end">
                                    <input type="number" step="0.0001" min="0.0001" :name="`items[${idx}][qty_ordered]`" dir="ltr"
                                           x-model.number="line.qty_ordered" class="w-20 text-end border-gray-300 rounded text-sm">
                                </td>
                                <td class="py-2 text-end">
                                    <input type="number" step="0.01" min="0" :name="`items[${idx}][cost_usd]`" dir="ltr"
                                           x-model.number="line.cost_usd" class="w-24 text-end border-gray-300 rounded text-sm">
                                </td>
                                <td class="py-2 text-end">
                                    <input type="number" step="0.0001" min="0" :name="`items[${idx}][tax_rate]`" dir="ltr"
                                           x-model.number="line.tax_rate" class="w-16 text-end border-gray-300 rounded text-sm">
                                </td>
                                <td class="py-2 text-end font-medium"><x-ltr>$<span x-text="lineTotal(line).toFixed(2)"></span></x-ltr></td>
                                <td class="py-2 text-end">
                                    <button type="button" @click="removeLine(idx)" class="text-red-500 text-sm">×</button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="lines.length === 0"><td colspan="6" class="py-6 text-center text-gray-400 text-sm">{{ __('purchases.no_lines') }}</td></tr>
                    </tbody>
                    <tfoot class="border-t-2 text-sm">
                        <tr><td colspan="4" class="py-2 text-end text-gray-500">{{ __('purchases.subtotal') }}</td><td class="py-2 text-end"><x-ltr>$<span x-text="totals().subtotal.toFixed(2)"></span></x-ltr></td><td></td></tr>
                        <tr><td colspan="4" class="py-1 text-end text-gray-500">{{ __('purchases.tax') }}</td><td class="py-1 text-end"><x-ltr>$<span x-text="totals().tax.toFixed(2)"></span></x-ltr></td><td></td></tr>
                        <tr><td colspan="4" class="py-2 text-end font-bold">{{ __('purchases.total_excl_shipping') }}</td><td class="py-2 text-end font-bold text-brand-700"><x-ltr>$<span x-text="totals().total.toFixed(2)"></span></x-ltr></td><td></td></tr>
                    </tfoot>
                </table>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('purchases.index') }}" class="px-4 py-2 bg-gray-100 rounded text-sm">{{ __('common.cancel') }}</a>
                <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">
                    {{ $isEdit ? __('common.save_changes') : __('purchases.create_po') }}
                </button>
            </div>
        </form>
    </div>

    @push('scripts')
    <script>
        function poForm() {
            return {
                products: @json($productsForJs),
                lines: @json($itemsForJs),
                picker: '',
                addLine() {
                    const id = Number(this.picker);
                    if (!id) return;
                    const p = this.products.find(x => x.id === id);
                    if (!p) return;
                    if (this.lines.find(l => l.product_id === id)) {
                        alert(@js(__('purchases.already_in_po')));
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
