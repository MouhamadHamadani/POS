{{--
    Receiving by package / carton, inside the stock-adjustment form of products/form.blade.php (client/tobacco, docs/packaging.md).
    - Unit: the quantity typed above is in this unit; the server converts it to packs (PackagingService) and logs what was typed.
    - Scan carton: a carton barcode finds its product and pre-fills "add, N cartons". Each further scan of the same carton adds one.
      A carton that belongs to another product opens that product's page with the same pre-fill. Nothing is saved until Adjust stock.
    Renders nothing unless POS_PACKAGING is on.
--}}
@if (\App\Models\Product::packagingEnabled())
    @php
        $rxUnits = $product->receivableUnits();
        $rx = [
            'packs' => [
                'pack' => 1,
                'package' => $product->definesUnit('package') ? $product->multiplierFor('package') : null,
                'carton' => $product->cartonPacks(),
            ],
            'productId' => $product->id,
            'checkUrl' => route('products.check-barcode'),
            'editUrl' => route('products.edit', ['product' => '__ID__']),
            'prefill' => request('receive') === 'carton' && $product->definesUnit('carton'),
            'strings' => [
                'unknown' => __('packaging.receive.carton_unknown'),
                'added' => __('packaging.receive.carton_added', ['name' => ':name', 'n' => ':n', 'packs' => ':packs']),
                'packs' => __('packaging.receive.packs_added', ['packs' => ':packs']),
                'reason' => __('packaging.receive.default_reason'),
            ],
            'name' => $product->localName(),
        ];
    @endphp

    <div class="md:col-span-4 grid grid-cols-1 md:grid-cols-4 gap-3 items-start" x-data="stockReceive(@js($rx))">
        <div>
            @if (count($rxUnits) > 1)
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('packaging.receive.unit') }}</label>
                <select name="unit" x-model="unit" class="w-full border-gray-300 rounded text-sm">
                    @foreach ($rxUnits as $u)
                        <option value="{{ $u }}">{{ __('packaging.unit.'.$u) }}@if ($u !== 'pack') ×{{ (float) $product->multiplierFor($u) }}@endif</option>
                    @endforeach
                </select>
                <div class="text-xs text-gray-500 mt-1" dir="ltr" x-text="packsHint()"></div>
            @else
                <input type="hidden" name="unit" value="pack">
            @endif
        </div>

        <div class="md:col-span-3">
            <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('packaging.receive.scan_carton') }}</label>
            <input type="text" dir="ltr" autocomplete="off" x-model="scanCode" @keydown.enter.prevent="scan()"
                   placeholder="{{ __('packaging.receive.scan_carton_placeholder') }}"
                   class="w-full border-gray-300 rounded text-sm" />
            <div class="text-xs mt-1 min-h-4" :class="err ? 'text-red-600' : 'text-green-700'" x-text="msg"></div>
        </div>
    </div>

    @push('scripts')
    <script>
    function stockReceive(c) {
        return {
            ...c,
            unit: c.prefill ? 'carton' : 'pack',
            scanCode: '', msg: '', err: false, typed: 0, form: null,

            init() {
                this.form = this.$root.closest('form');
                this.form.elements.qty.addEventListener('input', () => { this.typed = Number(this.form.elements.qty.value) || 0; });
                if (c.prefill) this.applyCarton(1);
            },

            packsHint() {
                const per = this.packs[this.unit] || 1;
                return this.unit !== 'pack' && this.typed > 0 ? this.strings.packs.replace(':packs', +(this.typed * per).toFixed(4)) : '';
            },

            // Pre-fill only: the cashier-side stock keeper still reviews the quantity and presses Adjust stock.
            applyCarton(n) {
                const f = this.form.elements;
                f.action.value = 'add';
                f.qty.value = n;
                this.typed = n;
                this.unit = 'carton';
                if (!f.reason.value.trim()) f.reason.value = this.strings.reason;
                this.err = false;
                this.msg = this.strings.added.replace(':name', this.name).replace(':n', n).replace(':packs', +(n * (this.packs.carton || 0)).toFixed(4));
            },

            scan() {
                const code = this.scanCode.trim();
                this.scanCode = '';
                if (!code) return;
                fetch(`${this.checkUrl}?barcode=${encodeURIComponent(code)}`, { headers: { Accept: 'application/json' } })
                    .then(r => r.ok ? r.json() : Promise.reject(r.status))
                    .then(d => {
                        if (!d.exists || d.kind !== 'carton') { this.err = true; this.msg = this.strings.unknown; return; }
                        if (d.product_id !== this.productId) {
                            window.location.href = this.editUrl.replace('__ID__', d.product_id) + '?receive=carton';
                            return;
                        }
                        const again = this.unit === 'carton' && this.form.elements.action.value === 'add' && this.typed > 0;
                        this.applyCarton(again ? this.typed + 1 : 1);
                    })
                    .catch(() => { this.err = true; this.msg = this.strings.unknown; });
            },
        };
    }
    </script>
    @endpush
@endif
