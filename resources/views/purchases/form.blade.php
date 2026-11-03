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
            // The stored rate is a 0..1 fraction; the "Tax %" input works in percent.
            'tax_pct' => round((float) $i->tax_rate * 100, 4),
        ])->values();

        // After a failed submit the lines come back from what was typed, not from the stored
        // lines (empty on create, the saved ones on edit). The form posts no product name, so it
        // is looked up; tax_rate was posted as a 0..1 fraction and goes back to a percentage.
        if (session()->hasOldInput()) {
            $names = $products->mapWithKeys(fn($p) => [$p->id => $p->localName()])
                ->union($items->mapWithKeys(fn($i) => [$i->product_id => $i->product?->localName() ?? $i->product_name]));
            $itemsForJs = collect(old('items', []))->filter(fn($l) => is_array($l))->map(fn($l) => [
                'id' => ($l['id'] ?? '') === '' ? null : (int) $l['id'],
                'product_id' => (int) ($l['product_id'] ?? 0),
                'product_name' => $names[(int) ($l['product_id'] ?? 0)] ?? '',
                'qty_ordered' => $l['qty_ordered'] ?? '',
                'cost_usd' => $l['cost_usd'] ?? '',
                'tax_pct' => ($l['tax_rate'] ?? '') === '' ? '' : round((float) $l['tax_rate'] * 100, 4),
            ])->values();
        }

        $productsForJs = $products->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->localName(),
            'sku' => $p->sku,
            'cost_usd' => $p->cost_usd,
            'tax' => $p->tax ? ['rate' => $p->tax->rate] : null,
        ])->values();

        $poType = old('type', $po->type ?? \App\Models\PurchaseOrder::TYPE_ITEMIZED);
        // Same unit list as the product form.
        $units = ['pcs', 'kg', 'g', 'L', 'mL', 'box', 'pair', 'set', 'm'];
    @endphp

    <div class="py-6 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8" x-data="poForm()" @keydown.escape.window="quick.open = false">
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm mb-4">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ $isEdit ? route('purchases.update', $po) : route('purchases.store') }}" class="space-y-4">
            @csrf
            @if ($isEdit) @method('PUT') @endif

            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs font-medium text-gray-600 mb-2">{{ __('purchases.type_label') }}</div>
                <input type="hidden" name="type" :value="type">
                <div class="inline-flex rounded border border-gray-300 overflow-hidden text-sm" role="group">
                    <button type="button" @click="setType('itemized')" :aria-pressed="type === 'itemized'"
                            :class="type === 'itemized' ? 'bg-brand-700 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                            class="px-4 py-2">{{ __('purchases.type_itemized') }}</button>
                    <button type="button" @click="setType('amount_only')" :aria-pressed="type === 'amount_only'"
                            :class="type === 'amount_only' ? 'bg-brand-700 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                            class="px-4 py-2 border-s border-gray-300">{{ __('purchases.type_amount_only') }}</button>
                </div>
                <p x-show="type === 'amount_only'" style="display: none" class="text-xs text-gray-500 mt-2">{{ __('purchases.amount_only_hint') }}</p>
            </div>

            <div class="bg-white rounded-lg shadow-card p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('purchases.supplier') }} *</label>
                    <select name="supplier_id" required class="w-full border-gray-300 rounded text-sm">
                        <option value="">{{ __('purchases.select') }}</option>
                        @foreach ($suppliers as $s)
                            <option value="{{ $s->id }}" @selected(old('supplier_id', $po->supplier_id) == $s->id)>{{ $s->name }}@unless ($s->is_active) ({{ __('common.inactive') }})@endunless</option>
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
                {{-- Disabled inputs are neither validated by the browser nor posted, so each type sends only its own fields. --}}
                <div x-show="type === 'itemized'">
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('purchases.shipping_usd') }}</label>
                    <input type="number" step="0.01" min="0" name="shipping_usd" dir="ltr" value="{{ old('shipping_usd', $po->shipping_usd ?? 0) }}" :disabled="type !== 'itemized'" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div x-show="type === 'amount_only'" style="display: none">
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('purchases.expected_amount_usd') }} *</label>
                    <input type="number" step="0.01" min="0.01" name="expected_amount_usd" dir="ltr" required :disabled="type !== 'amount_only'"
                           value="{{ old('expected_amount_usd', $po->isAmountOnly() ? (float) $po->total_usd : '') }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('common.notes') }}</label>
                    <textarea name="notes" dir="auto" rows="2" class="w-full border-gray-300 rounded text-sm">{{ old('notes', $po->notes) }}</textarea>
                </div>
            </div>

            {{-- x-if, not x-show: an amount-only PO posts no lines at all. The lines stay in Alpine state if the type is switched back. --}}
            <template x-if="type === 'itemized'">
            <div class="bg-white rounded-lg shadow-card p-6">
                <div class="flex flex-wrap gap-2 justify-between items-center mb-3">
                    <h3 class="font-semibold text-sm">{{ __('purchases.line_items') }}</h3>
                    <div class="flex flex-wrap gap-2 items-center">
                        <select x-model.number="picker" class="border-gray-300 rounded text-sm">
                            <option value="">{{ __('purchases.add_product') }}</option>
                            <template x-for="p in products" :key="p.id">
                                <option :value="p.id" x-text="`${p.name}${p.sku ? ' — ' + p.sku : ''}`"></option>
                            </template>
                        </select>
                        <button type="button" @click="addLine()" class="px-3 py-1.5 text-xs bg-brand-700 text-white rounded">{{ __('common.add') }}</button>
                        <button type="button" @click="openQuick()" class="px-3 py-1.5 text-xs bg-gray-100 text-gray-800 rounded hover:bg-gray-200">{{ __('products.new_product') }}</button>
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
                                    <input type="number" step="0.0001" min="0.0001" required :name="`items[${idx}][qty_ordered]`" dir="ltr"
                                           x-model.number="line.qty_ordered" class="w-20 text-end border-gray-300 rounded text-sm">
                                </td>
                                <td class="py-2 text-end">
                                    <input type="number" step="0.01" min="0" required :name="`items[${idx}][cost_usd]`" dir="ltr"
                                           x-model.number="line.cost_usd" class="w-24 text-end border-gray-300 rounded text-sm">
                                </td>
                                <td class="py-2 text-end">
                                    {{-- Typed as a percentage; the server contract is the 0..1 fraction, sent by the hidden input. --}}
                                    <input type="hidden" :name="`items[${idx}][tax_rate]`" :value="rateOf(line)">
                                    <input type="number" step="0.01" min="0" max="100" dir="ltr"
                                           x-model.number="line.tax_pct" class="w-16 text-end border-gray-300 rounded text-sm">
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
            </template>

            <div class="flex justify-end gap-2">
                <a href="{{ route('purchases.index') }}" class="px-4 py-2 bg-gray-100 rounded text-sm">{{ __('common.cancel') }}</a>
                <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">
                    {{ $isEdit ? __('common.save_changes') : __('purchases.create_po') }}
                </button>
            </div>
        </form>

        {{-- "+ New product": outside the PO form so its fields are never posted with the order. --}}
        <div x-show="quick.open" style="display: none" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-5 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="text-lg font-bold">{{ __('products.new_product_title') }}</h3>
                    <button type="button" @click="quick.open = false" class="text-gray-400 hover:text-gray-700 text-xl" aria-label="{{ __('common.close') }}">&times;</button>
                </div>

                @if ($categories->isEmpty())
                    <p class="text-sm text-gray-700">{{ __('purchases.quick_no_categories') }}</p>
                    <a href="{{ route('categories.index') }}" target="_blank" class="inline-block mt-2 text-sm text-brand-600 hover:underline">{{ __('products.manage_categories') }}</a>
                @else
                    <form @submit.prevent="saveQuick()" class="space-y-3 text-sm">
                        <p class="text-xs text-gray-500">{{ __('purchases.quick_hint') }}</p>
                        <div x-show="quickErrors().length" class="p-2 bg-red-50 text-red-700 rounded text-xs">
                            <ul class="list-disc list-inside"><template x-for="e in quickErrors()" :key="e"><li x-text="e"></li></template></ul>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('categories.name_en') }} *</label>
                            <input type="text" x-ref="quickName" x-model="quick.form.name" required maxlength="255" dir="auto" class="w-full border-gray-300 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('categories.name_ar') }}</label>
                            <input type="text" x-model="quick.form.name_ar" maxlength="255" dir="rtl" class="w-full border-gray-300 rounded text-sm">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.category') }} *</label>
                                <select x-model.number="quick.form.category_id" required class="w-full border-gray-300 rounded text-sm">
                                    @foreach ($categories as $c)
                                        <option value="{{ $c->id }}">{{ $c->localName() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('reports.cols.unit') }}</label>
                                <select x-model="quick.form.unit" class="w-full border-gray-300 rounded text-sm">
                                    @foreach ($units as $u)
                                        <option value="{{ $u }}">{{ __("enums.unit.$u") }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('purchases.cost_usd') }}</label>
                                <input type="number" step="0.01" min="0" x-model.number="quick.form.cost_usd" dir="ltr" class="w-full border-gray-300 rounded text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.tax_rate') }}</label>
                                <select x-model.number="quick.form.tax_id" class="w-full border-gray-300 rounded text-sm">
                                    <option value="">{{ __('products.no_tax') }}</option>
                                    @foreach ($taxes as $t)
                                        <option value="{{ $t->id }}">{{ app()->getLocale() === 'ar' && $t->name_ar ? $t->name_ar : $t->name }} ({{ number_format((float) $t->rate * 100, 2) }}%)</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" @click="quick.open = false" class="px-4 py-2 bg-gray-100 rounded text-sm">{{ __('common.cancel') }}</button>
                            <button type="submit" :disabled="quick.saving" class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800 disabled:opacity-50">{{ __('purchases.quick_save') }}</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        function poForm() {
            return {
                products: @json($productsForJs),
                lines: @json($itemsForJs),
                picker: '',
                type: @js($poType === 'amount_only' ? 'amount_only' : 'itemized'),
                quick: { open: false, saving: false, errors: {}, form: {} },
                // Itemized -> amount only drops the lines on save, so ask before switching.
                setType(t) {
                    if (t === this.type) return;
                    if (t === 'amount_only' && this.lines.length && !confirm(@js(__('purchases.confirm_switch_amount_only')))) return;
                    this.type = t;
                },
                openQuick() {
                    this.quick.form = {
                        name: '', name_ar: '', unit: 'pcs', cost_usd: '',
                        category_id: @js($categories->first()?->id),
                        tax_id: @js($defaultTaxId ?? ''),
                    };
                    this.quick.errors = {};
                    this.quick.open = true;
                    this.$nextTick(() => this.$refs.quickName?.focus());
                },
                quickErrors() { return Object.values(this.quick.errors).flat(); },
                async saveQuick() {
                    this.quick.saving = true;
                    this.quick.errors = {};
                    try {
                        const res = await fetch(@js(route('products.quick-store')), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ ...this.quick.form, tax_id: this.quick.form.tax_id || null, cost_usd: this.quick.form.cost_usd === '' ? null : this.quick.form.cost_usd }),
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) {
                            this.quick.errors = data.errors || { _: [data.message || @js(__('purchases.quick_failed'))] };
                            return;
                        }
                        this.products.push(data);
                        this.picker = data.id;
                        this.addLine();
                        this.quick.open = false;
                    } catch (e) {
                        this.quick.errors = { _: [@js(__('purchases.quick_failed'))] };
                    } finally {
                        this.quick.saving = false;
                    }
                },
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
                        tax_pct: p.tax ? Number((Number(p.tax.rate) * 100).toFixed(4)) : 0,
                    });
                    this.picker = '';
                },
                removeLine(i) { this.lines.splice(i, 1); },
                // Percent typed in the line -> the 0..1 fraction the server stores. Blank stays blank
                // (the server then falls back to the product's own tax).
                rateOf(line) {
                    return line.tax_pct === '' || line.tax_pct == null ? '' : Number((Number(line.tax_pct) / 100).toFixed(4));
                },
                lineTotal(line) {
                    const sub = Number(line.qty_ordered || 0) * Number(line.cost_usd || 0);
                    return sub * (1 + Number(this.rateOf(line) || 0));
                },
                totals() {
                    let subtotal = 0, tax = 0;
                    for (const l of this.lines) {
                        const sub = Number(l.qty_ordered || 0) * Number(l.cost_usd || 0);
                        subtotal += sub;
                        tax += sub * Number(this.rateOf(l) || 0);
                    }
                    return { subtotal, tax, total: subtotal + tax };
                },
            }
        }
    </script>
    @endpush
</x-app-layout>
