{{--
    Packaging section of the product form (client/tobacco, docs/packaging.md). Included once from products/form.blade.php,
    inside its <form>, so every field below posts with the product. Renders nothing unless POS_PACKAGING is on.
    Collapsed by default; open when the product already has packaging or a packaging field failed validation.
--}}
@if (\App\Models\Product::packagingEnabled())
    @php
        $pkgNum = fn ($v) => ($v === null || $v === '') ? '' : (float) $v;
        $pkgBlocked = $isEdit && ($product->type !== \App\Models\Product::TYPE_SIMPLE || $product->variants()->exists());
        $pkgFields = ['has_package', 'package_size', 'package_price_usd', 'package_wholesale_price_usd', 'package_vip_price_usd',
            'carton_barcode', 'packages_per_carton', 'sell_carton_at_pos', 'carton_price_usd', 'carton_wholesale_price_usd', 'carton_vip_price_usd'];
        $pkg = [
            'blocked' => $pkgBlocked,
            'open' => (bool) old('has_package', $product->has_package) || $errors->hasAny($pkgFields),
            'hasPackage' => (bool) old('has_package', $product->has_package),
            'packageSize' => $pkgNum(old('package_size', $product->package_size ?: 10)),
            'pkgRetail' => $pkgNum(old('package_price_usd', $product->package_price_usd)),
            'pkgWholesale' => $pkgNum(old('package_wholesale_price_usd', $product->package_wholesale_price_usd)),
            'pkgVip' => $pkgNum(old('package_vip_price_usd', $product->package_vip_price_usd)),
            'cartonBarcode' => (string) old('carton_barcode', $product->carton_barcode),
            'packagesPerCarton' => $pkgNum(old('packages_per_carton', $product->packages_per_carton)),
            'sellCarton' => (bool) old('sell_carton_at_pos', $product->sell_carton_at_pos),
            'cartonRetail' => $pkgNum(old('carton_price_usd', $product->carton_price_usd)),
            'cartonWholesale' => $pkgNum(old('carton_wholesale_price_usd', $product->carton_wholesale_price_usd)),
            'cartonVip' => $pkgNum(old('carton_vip_price_usd', $product->carton_vip_price_usd)),
            'checkUrl' => route('products.check-barcode'),
            'except' => $isEdit ? $product->id : null,
            'perPack' => __('packaging.form.per_pack', ['price' => ':price']),
            'takenBy' => __('products.taken_by', ['name' => ':name']),
        ];
    @endphp

    <div class="border-t" x-data="packagingSection(@js($pkg))"
         @change.window="if ($event.target.name === 'type') typeOk = $event.target.value === 'simple'">
        <button type="button" @click="open = !open" :aria-expanded="open"
                class="w-full px-6 py-3 flex items-center justify-between text-sm font-semibold text-gray-700 hover:bg-gray-50">
            <span>{{ __('packaging.form.section') }}</span>
            <span class="text-gray-400" aria-hidden="true" x-text="open ? '▴' : '▾'"></span>
        </button>

        <div x-show="open" style="display: none" class="px-6 pb-6 space-y-4 text-sm">
            <p class="text-xs text-gray-500">{{ __('packaging.form.section_hint') }}</p>
            <p x-show="blocked || !typeOk" style="display: none" class="text-xs p-2 rounded bg-amber-50 text-amber-800">{{ __('packaging.form.simple_only_hint') }}</p>

            {{-- PACKAGE: N packs, own prices. No barcode: the cashier reaches it by scanning a pack. --}}
            <div class="space-y-3">
                <input type="hidden" name="has_package" value="0">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="has_package" value="1" x-model="hasPackage" :disabled="blocked || !typeOk">
                    {{ __('packaging.form.has_package') }}
                </label>

                <div x-show="hasPackage" style="display: none" class="grid grid-cols-1 md:grid-cols-4 gap-3 ps-6">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('packaging.form.package_size') }}</label>
                        <input type="number" name="package_size" dir="ltr" min="2" max="1000" step="1" x-model.number="packageSize"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('packaging.form.price_retail') }} ($) *</label>
                        <input type="number" name="package_price_usd" dir="ltr" min="0" step="0.01" x-model.number="pkgRetail"
                               class="w-full border-gray-300 rounded text-sm" />
                        <div class="text-xs text-gray-500 mt-1" dir="ltr" x-text="perPackText(pkgRetail, packageSize)"></div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('packaging.form.price_wholesale') }} ($)</label>
                        <input type="number" name="package_wholesale_price_usd" dir="ltr" min="0" step="0.01" x-model.number="pkgWholesale"
                               class="w-full border-gray-300 rounded text-sm" />
                        <div class="text-xs text-gray-500 mt-1" dir="ltr" x-text="perPackText(pkgWholesale, packageSize)"></div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('packaging.form.price_vip') }} ($)</label>
                        <input type="number" name="package_vip_price_usd" dir="ltr" min="0" step="0.01" x-model.number="pkgVip"
                               class="w-full border-gray-300 rounded text-sm" />
                        <div class="text-xs text-gray-500 mt-1" dir="ltr" x-text="perPackText(pkgVip, packageSize)"></div>
                    </div>
                </div>
            </div>

            {{-- CARTON: a barcode of its own, N packages. Only offered once the package exists (carton packs = package size x packages). --}}
            <div class="space-y-3" x-show="hasPackage" style="display: none">
                <label class="flex items-center gap-2">
                    <input type="checkbox" x-model="hasCarton">
                    {{ __('packaging.form.has_carton') }}
                </label>

                <div x-show="hasCarton" style="display: none" class="space-y-3 ps-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('packaging.form.carton_barcode') }}</label>
                            <input type="text" name="carton_barcode" dir="ltr" autocomplete="off" x-model="cartonBarcode"
                                   @keydown.enter.prevent="checkCarton()" @input.debounce.400ms="checkCarton()"
                                   placeholder="{{ __('packaging.form.carton_barcode_placeholder') }}"
                                   class="w-full border-gray-300 rounded text-sm" />
                            <div class="text-xs mt-1 h-4">
                                <span x-show="cartonStatus==='checking'" style="display: none" class="text-gray-400">{{ __('products.checking') }}</span>
                                <span x-show="cartonStatus==='free'" style="display: none" class="text-green-600">✓ {{ __('products.available') }}</span>
                                <span x-show="cartonStatus==='taken'" style="display: none" class="text-red-600" x-text="'✕ ' + takenBy.replace(':name', cartonTakenBy)"></span>
                                <span x-show="cartonStatus==='same'" style="display: none" class="text-red-600">✕ {{ __('packaging.form.carton_is_pack') }}</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('packaging.form.packages_per_carton') }}</label>
                            <input type="number" name="packages_per_carton" dir="ltr" min="1" max="1000" step="1" x-model.number="packagesPerCarton"
                                   class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('packaging.form.packs_per_carton') }}</label>
                            <div class="px-3 py-2 bg-gray-50 rounded text-sm font-semibold" dir="ltr" x-text="cartonPacks() || '—'"></div>
                        </div>
                    </div>

                    <input type="hidden" name="sell_carton_at_pos" value="0">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="sell_carton_at_pos" value="1" x-model="sellCarton">
                        {{ __('packaging.form.sell_carton') }}
                    </label>
                    <p class="text-xs text-gray-500 ps-6" x-show="!sellCarton" style="display: none">{{ __('packaging.form.sell_carton_hint') }}</p>

                    <div x-show="sellCarton" style="display: none" class="grid grid-cols-1 md:grid-cols-3 gap-3 ps-6">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('packaging.form.price_retail') }} ($) *</label>
                            <input type="number" name="carton_price_usd" dir="ltr" min="0" step="0.01" x-model.number="cartonRetail"
                                   class="w-full border-gray-300 rounded text-sm" />
                            <div class="text-xs text-gray-500 mt-1" dir="ltr" x-text="perPackText(cartonRetail, cartonPacks())"></div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('packaging.form.price_wholesale') }} ($)</label>
                            <input type="number" name="carton_wholesale_price_usd" dir="ltr" min="0" step="0.01" x-model.number="cartonWholesale"
                                   class="w-full border-gray-300 rounded text-sm" />
                            <div class="text-xs text-gray-500 mt-1" dir="ltr" x-text="perPackText(cartonWholesale, cartonPacks())"></div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('packaging.form.price_vip') }} ($)</label>
                            <input type="number" name="carton_vip_price_usd" dir="ltr" min="0" step="0.01" x-model.number="cartonVip"
                                   class="w-full border-gray-300 rounded text-sm" />
                            <div class="text-xs text-gray-500 mt-1" dir="ltr" x-text="perPackText(cartonVip, cartonPacks())"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
    function packagingSection(c) {
        return {
            ...c,
            typeOk: true,                       // follows the Type select on the Basic tab
            hasCarton: c.cartonBarcode !== '',
            cartonStatus: null,                 // null | 'checking' | 'free' | 'taken' | 'same'
            cartonTakenBy: '',
            _last: null,

            cartonPacks() { return (Number(this.packageSize) || 0) * (Number(this.packagesPerCarton) || 0); },

            // "= $0.85 per pack": a price per package or carton, shown per pack so it can be compared with the pack price.
            perPackText(price, packs) {
                price = Number(price); packs = Number(packs);
                return price > 0 && packs > 0 ? this.perPack.replace(':price', '$' + (price / packs).toFixed(4).replace(/0+$/, '').replace(/\.$/, '')) : '';
            },

            // Unticking a section clears it: what is hidden must not be posted (the server stores nothing for it either).
            init() {
                this.$watch('hasPackage', (on) => { if (!on) this.hasCarton = false; });
                this.$watch('hasCarton', (on) => {
                    if (!on) Object.assign(this, { cartonBarcode: '', packagesPerCarton: '', sellCarton: false, cartonRetail: '', cartonWholesale: '', cartonVip: '', cartonStatus: null, _last: null });
                });
            },

            // Same live check as the pack barcode: a scanner's Enter checks the code, it never submits the form.
            checkCarton() {
                const code = this.cartonBarcode.trim();
                if (!code) { this.cartonStatus = null; return; }
                if (code === (document.querySelector('input[name=barcode]')?.value || '').trim()) { this.cartonStatus = 'same'; return; }
                if (code === this._last) return;

                this.cartonStatus = 'checking';
                fetch(`${this.checkUrl}?barcode=${encodeURIComponent(code)}${this.except ? '&except=' + this.except : ''}`, { headers: { Accept: 'application/json' } })
                    .then(r => r.ok ? r.json() : Promise.reject(r.status))
                    .then(d => {
                        if (code !== this.cartonBarcode.trim()) return;
                        this._last = code;
                        this.cartonStatus = d.exists ? 'taken' : 'free';
                        this.cartonTakenBy = d.product_name ?? '';
                    })
                    .catch(() => { this.cartonStatus = null; }); // fail closed, like the pack barcode: the server rule decides
            },
        };
    }
    </script>
    @endpush
@endif
