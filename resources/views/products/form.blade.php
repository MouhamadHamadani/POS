@php $isEdit = (bool) $product->id; @endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $isEdit ? __('products.edit_product') : __('products.new_product_title') }}</h2>
            <a href="{{ route('products.index') }}" class="text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('categories.back_to_products') }}</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4" x-data="productForm()">

        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $isEdit ? route('products.update', $product) : route('products.store') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            @if ($isEdit) @method('PUT') @endif

            <div class="bg-white rounded-lg shadow-sm">
                <div class="flex border-b text-sm">
                    <button type="button" @click="tab='basic'" :class="tab==='basic' ? 'border-b-2 border-brand-600 text-brand-700' : 'text-gray-600'" class="px-4 py-3">{{ __('products.tab_basic') }}</button>
                    <button type="button" @click="tab='pricing'" :class="tab==='pricing' ? 'border-b-2 border-brand-600 text-brand-700' : 'text-gray-600'" class="px-4 py-3">{{ __('products.tab_pricing') }}</button>
                    <button type="button" @click="tab='stock'" :class="tab==='stock' ? 'border-b-2 border-brand-600 text-brand-700' : 'text-gray-600'" class="px-4 py-3">{{ __('products.tab_stock') }}</button>
                    <button type="button" @click="tab='tax'" :class="tab==='tax' ? 'border-b-2 border-brand-600 text-brand-700' : 'text-gray-600'" class="px-4 py-3">{{ __('products.tab_tax') }}</button>
                    @if ($isEdit && feature('stock_movements'))
                        <button type="button" @click="tab='movements'" :class="tab==='movements' ? 'border-b-2 border-brand-600 text-brand-700' : 'text-gray-600'" class="px-4 py-3">{{ __('products.tab_movements') }}</button>
                    @endif
                </div>

                {{-- MOVEMENTS (edit only): latest rows of this product's stock history.
                     The history is the stock_movements feature; the rows behind it are still written. --}}
                @if ($isEdit && feature('stock_movements'))
                    <div x-show="tab==='movements'" x-cloak class="p-4 space-y-3">
                        @include('products._movements-table', ['rows' => $movements, 'showProduct' => false])
                        <a href="{{ route('products.movements', ['product_id' => $product->id, 'from' => '2000-01-01', 'to' => now()->format('Y-m-d')]) }}"
                           class="inline-block text-sm text-brand-700 hover:underline">{{ __('stock.full_history') }}</a>
                    </div>
                @endif

                {{-- BASIC --}}
                <div x-show="tab==='basic'" class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('categories.name_en') }} *</label>
                            <input type="text" name="name" dir="auto" value="{{ old('name', $product->name) }}" required
                                   class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('categories.name_ar') }}</label>
                            <input type="text" name="name_ar" value="{{ old('name_ar', $product->name_ar) }}" dir="rtl"
                                   class="w-full border-gray-300 rounded text-sm" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.category') }} *</label>
                        <select name="category_id" required class="w-full border-gray-300 rounded text-sm">
                            <option value="">{{ __('purchases.select') }}</option>
                            @foreach ($categories as $c)
                                <option value="{{ $c->id }}" @selected(old('category_id', $product->category_id) == $c->id)>{{ $c->localName() }}</option>
                            @endforeach
                        </select>
                        <div class="text-xs text-gray-500 mt-1">
                            <a href="{{ route('categories.index') }}" target="_blank" class="text-brand-600 hover:underline">{{ __('products.manage_categories') }}</a>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.product_type') }}</label>
                        <select name="type" class="w-full border-gray-300 rounded text-sm">
                            @foreach (['simple', 'service', 'bundle', 'variant'] as $t)
                                <option value="{{ $t }}" @selected(old('type', $product->type) === $t)>{{ __("enums.product_type.$t") }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">SKU</label>
                        <input type="text" name="sku" dir="ltr" value="{{ old('sku', $product->sku) }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.barcode_auto') }}</label>
                        <div class="relative">
                            <input type="text" name="barcode" dir="ltr" x-ref="barcode" x-model="barcode"
                                   @keydown.enter.prevent="onBarcodeEnter()"
                                   @input.debounce.400ms="checkBarcode()"
                                   placeholder="{{ __('products.barcode_placeholder') }}"
                                   autocomplete="off"
                                   class="w-full border-gray-300 rounded text-sm pe-16" />
                            <button type="button" @click="focusScan()"
                                    class="absolute end-1 top-1/2 -translate-y-1/2 px-2 py-1 text-xs bg-gray-100 rounded hover:bg-gray-200"
                                    title="{{ __('products.scan_title') }}">
                                {{ __('products.scan') }}
                            </button>
                        </div>
                        <div class="text-xs mt-1 h-4">
                            <span x-show="barcodeStatus==='checking'" x-cloak class="text-gray-400">{{ __('products.checking') }}</span>
                            <span x-show="barcodeStatus==='free'" x-cloak class="text-green-600">✓ {{ __('products.available') }}</span>
                            <span x-show="barcodeStatus==='taken'" x-cloak class="text-red-600" x-text="takenMessage()"></span>
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.description') }}</label>
                        <textarea name="description" dir="auto" rows="3" class="w-full border-gray-300 rounded text-sm">{{ old('description', $product->description) }}</textarea>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.image') }}</label>
                        @if ($product->image)
                            <div class="flex items-center gap-3 mb-2">
                                <img src="{{ Storage::url($product->image) }}" class="w-20 h-20 rounded object-cover">
                                <label class="text-xs text-red-600 flex items-center gap-1">
                                    <input type="checkbox" name="remove_image" value="1"> {{ __('products.remove_image') }}
                                </label>
                            </div>
                        @endif
                        <input type="file" name="image" accept="image/*" class="text-sm" />
                        <div class="text-xs text-gray-500 mt-1">{{ __('products.image_hint') }}</div>
                    </div>
                </div>

                {{-- PRICING --}}
                <div x-show="tab==='pricing'" x-cloak class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4"
                     x-data="{ price: {{ (float) old('price_usd', $product->price_usd ?: 0) }}, cost: {{ (float) old('cost_usd', $product->cost_usd ?: 0) }} }">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('purchases.cost_usd') }}</label>
                        <input type="number" name="cost_usd" dir="ltr" step="0.01" min="0" x-model.number="cost"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.retail_price_usd') }} *</label>
                        <input type="number" name="price_usd" dir="ltr" step="0.01" min="0" x-model.number="price" required
                               class="w-full border-gray-300 rounded text-sm" />
                        <div class="text-xs mt-1" x-show="cost > 0">
                            {{ __('products.margin') }}: <span class="font-semibold" dir="ltr" x-text="((price - cost) / Math.max(price, 0.0001) * 100).toFixed(1) + '%'"></span>
                            ({{ __('products.profit') }}: <x-ltr>$<span x-text="(price - cost).toFixed(2)"></span></x-ltr>)
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.wholesale_price_usd') }}</label>
                        <input type="number" name="wholesale_price_usd" dir="ltr" step="0.01" min="0"
                               value="{{ old('wholesale_price_usd', $product->wholesale_price_usd) }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.vip_price_usd') }}</label>
                        <input type="number" name="vip_price_usd" dir="ltr" step="0.01" min="0"
                               value="{{ old('vip_price_usd', $product->vip_price_usd) }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>

                    <div class="md:col-span-2 bg-gray-50 p-3 rounded text-xs">
                        {{ __('products.lbp_help') }}
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.lbp_price') }}</label>
                        {{-- The step is the store's LBP rounding step (0 = no rounding, any amount), not a fixed 1000. A price already stored (or typed) that is not a multiple of it (step raised later, bulk import) gets step="any": a browser refuses to submit a value off its step, which would lock the whole product form. --}}
                        @php
                            $lbpStep = app(\App\Services\CurrencyService::class)->getRoundingStep();
                            $lbpShown = old('price_lbp', $product->price_lbp);
                            $lbpAnyStep = $lbpStep <= 0 || (filled($lbpShown) && fmod((float) $lbpShown, $lbpStep) != 0);
                        @endphp
                        <input type="number" name="price_lbp" dir="ltr" step="{{ $lbpAnyStep ? 'any' : $lbpStep }}" min="0"
                               value="{{ $lbpShown }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div class="flex items-end">
                        <label class="text-sm flex items-center gap-2">
                            {{-- Every checkbox on this form has a hidden 0 first: an un-ticked box sends nothing, so old() would be empty and the stored/default value would come back after a validation error. --}}
                            <input type="hidden" name="force_lbp_price" value="0">
                            <input type="checkbox" name="force_lbp_price" value="1" @checked(old('force_lbp_price', $product->force_lbp_price))>
                            {{ __('products.force_lbp') }}
                        </label>
                    </div>
                </div>

                {{-- STOCK --}}
                <div x-show="tab==='stock'" x-cloak class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2 flex items-center gap-2">
                        <input type="hidden" name="track_stock" value="0">
                        <input type="checkbox" name="track_stock" value="1" id="track_stock" @checked(old('track_stock', $product->track_stock))>
                        <label for="track_stock" class="text-sm">{{ __('products.track_stock') }}</label>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.current_stock') }}</label>
                        <input type="number" name="stock_qty" dir="ltr" step="0.0001" min="0"
                               value="{{ old('stock_qty', $product->stock_qty) }}"
                               class="w-full border-gray-300 rounded text-sm" {{ $isEdit ? 'readonly' : '' }} />
                        @if ($isEdit)
                            <div class="text-xs text-gray-500 mt-1">{{ __('products.use_adjustment') }}</div>
                        @endif
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.min_stock') }}</label>
                        <input type="number" name="min_stock" dir="ltr" step="0.0001" min="0"
                               value="{{ old('min_stock', $product->min_stock) }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.max_stock') }}</label>
                        <input type="number" name="max_stock" dir="ltr" step="0.0001" min="0"
                               value="{{ old('max_stock', $product->max_stock) }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('reports.cols.unit') }}</label>
                        @php
                            $units = ['pcs','kg','g','L','mL','box','pair','set','m'];
                            $currentUnit = (string) old('unit', $product->unit);
                        @endphp
                        <select name="unit" class="w-full border-gray-300 rounded text-sm">
                            @foreach ($units as $u)
                                <option value="{{ $u }}" @selected($currentUnit === $u)>{{ __("enums.unit.$u") }}</option>
                            @endforeach
                            {{-- `unit` is a free string (bulk import takes any value): keep a stored unit that is not
                                 in the list as an extra option, or Save would submit the first one ("pcs"). --}}
                            @if ($currentUnit !== '' && ! in_array($currentUnit, $units, true))
                                <option value="{{ $currentUnit }}" selected>{{ $currentUnit }}</option>
                            @endif
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.location') }}</label>
                        <input type="text" name="location" dir="auto" value="{{ old('location', $product->location) }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                </div>

                {{-- TAX --}}
                <div x-show="tab==='tax'" x-cloak class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.tax_rate') }}</label>
                        <select name="tax_id" class="w-full border-gray-300 rounded text-sm">
                            <option value="">{{ __('products.no_tax') }}</option>
                            @foreach ($taxes as $t)
                                {{-- The default tax is only a suggestion for a NEW product. A saved product with no tax stays "No tax". --}}
                                <option value="{{ $t->id }}" @selected(old('tax_id', $product->exists ? $product->tax_id : $defaultTaxId) == $t->id)>
                                    {{ app()->getLocale() === 'ar' && $t->name_ar ? $t->name_ar : $t->name }} ({{ number_format((float) $t->rate * 100, 2) }}%)@unless ($t->is_active) ({{ __('common.inactive') }})@endunless
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-end">
                        <label class="text-sm flex items-center gap-2">
                            <input type="hidden" name="is_taxable" value="0">
                            <input type="checkbox" name="is_taxable" value="1" @checked(old('is_taxable', $product->is_taxable))>
                            {{ __('products.taxable') }}
                        </label>
                    </div>

                    <div class="flex items-center">
                        <label class="text-sm flex items-center gap-2">
                            <input type="hidden" name="allow_discount" value="0">
                            <input type="checkbox" name="allow_discount" value="1" @checked(old('allow_discount', $product->allow_discount))>
                            {{ __('products.allow_discount') }}
                        </label>
                    </div>
                    <div class="flex items-center">
                        <label class="text-sm flex items-center gap-2">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active))>
                            {{ __('products.active_sellable') }}
                        </label>
                    </div>
                </div>

                <div class="border-t p-4 flex justify-end gap-2">
                    <a href="{{ route('products.index') }}" class="px-4 py-2 text-sm bg-gray-100 rounded">{{ __('common.cancel') }}</a>
                    <button class="px-4 py-2 text-sm bg-brand-600 text-white rounded hover:bg-brand-700">
                        {{ $isEdit ? __('common.save_changes') : __('products.create_product') }}
                    </button>
                </div>
            </div>
        </form>

        {{-- Manual stock adjustment (edit only) --}}
        @if ($isEdit)
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h3 class="font-semibold mb-3">{{ __('products.stock_adjustment') }}</h3>
                <form method="POST" action="{{ route('products.adjust-stock', $product) }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end text-sm">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.action') }}</label>
                        <select name="action" class="w-full border-gray-300 rounded text-sm" required>
                            <option value="add">{{ __('products.add_to_stock') }}</option>
                            <option value="remove">{{ __('products.remove_from_stock') }}</option>
                            <option value="set">{{ __('products.set_absolute') }}</option>
                            <option value="count">{{ __('products.stock_count') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('products.quantity') }}</label>
                        <input type="number" name="qty" dir="ltr" step="0.0001" min="0" value="0" required
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('returns.reason') }}</label>
                        <input type="text" name="reason" dir="auto" placeholder="{{ __('products.reason_placeholder') }}" required
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div class="md:col-span-4 text-end">
                        <button class="px-4 py-2 bg-orange-600 text-white rounded text-sm hover:bg-orange-700">{{ __('products.adjust_stock') }}</button>
                    </div>
                </form>
            </div>
        @endif
    </div>

    @push('scripts')
    <script>
    function productForm() {
        return {
            tab: 'basic',
            barcode: @js(old('barcode', $product->barcode) ?? ''),
            barcodeStatus: null, // null | 'checking' | 'free' | 'taken'
            barcodeTakenBy: '',
            _lastChecked: null,

            takenMessage() {
                return '✕ ' + @js(__('products.taken_by', ['name' => ':name'])).replace(':name', this.barcodeTakenBy);
            },

            focusScan() {
                this.$refs.barcode.focus();
                this.$refs.barcode.select();
            },

            onBarcodeEnter() {
                // Scanners send Enter after the digits — check the code, don't submit the form.
                // If the debounced check already cleared this exact code, checkBarcode()
                // no-ops and the verdict on screen stays as it is.
                if (this.barcode.trim()) this.checkBarcode();
            },

            checkBarcode() {
                const code = this.barcode.trim();
                if (!code) { this.barcodeStatus = null; return; }
                if (code === this._lastChecked) return;

                this.barcodeStatus = 'checking';

                fetch(`{{ route('products.check-barcode') }}?barcode=${encodeURIComponent(code)}@if($isEdit)&except={{ $product->id }}@endif`,
                    { headers: { Accept: 'application/json' } })
                    .then(r => r.ok ? r.json() : Promise.reject(r.status))
                    .then(data => {
                        // A late reply for a code the admin has already typed past is noise.
                        if (code !== this.barcode.trim()) return;
                        this._lastChecked = code;
                        this.barcodeStatus = data.exists ? 'taken' : 'free';
                        this.barcodeTakenBy = data.product_name ?? '';
                    })
                    // Fail closed. A 500/419/403 must not render "Available" on a code we
                    // never actually cleared — show nothing and let the server rule decide.
                    .catch(() => { this.barcodeStatus = null; });
            },
        };
    }
    </script>
    @endpush
</x-app-layout>
