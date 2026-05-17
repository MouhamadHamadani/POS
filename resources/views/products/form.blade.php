@php $isEdit = (bool) $product->id; @endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $isEdit ? 'Edit Product' : 'New Product' }}</h2>
            <a href="{{ route('products.index') }}" class="text-sm text-gray-600 hover:underline">← Back to products</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4" x-data="{ tab: 'basic' }">

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
                    <button type="button" @click="tab='basic'" :class="tab==='basic' ? 'border-b-2 border-blue-600 text-blue-700' : 'text-gray-600'" class="px-4 py-3">Basic Info</button>
                    <button type="button" @click="tab='pricing'" :class="tab==='pricing' ? 'border-b-2 border-blue-600 text-blue-700' : 'text-gray-600'" class="px-4 py-3">Pricing</button>
                    <button type="button" @click="tab='stock'" :class="tab==='stock' ? 'border-b-2 border-blue-600 text-blue-700' : 'text-gray-600'" class="px-4 py-3">Stock</button>
                    <button type="button" @click="tab='tax'" :class="tab==='tax' ? 'border-b-2 border-blue-600 text-blue-700' : 'text-gray-600'" class="px-4 py-3">Tax &amp; Options</button>
                </div>

                {{-- BASIC --}}
                <div x-show="tab==='basic'" class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Name (English) *</label>
                            <input type="text" name="name" value="{{ old('name', $product->name) }}" required
                                   class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Name (Arabic)</label>
                            <input type="text" name="name_ar" value="{{ old('name_ar', $product->name_ar) }}" dir="rtl"
                                   class="w-full border-gray-300 rounded text-sm" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Category *</label>
                        <select name="category_id" required class="w-full border-gray-300 rounded text-sm">
                            <option value="">— Select —</option>
                            @foreach ($categories as $c)
                                <option value="{{ $c->id }}" @selected(old('category_id', $product->category_id) == $c->id)>{{ $c->name }}</option>
                            @endforeach
                        </select>
                        <div class="text-xs text-gray-500 mt-1">
                            <a href="{{ route('categories.index') }}" target="_blank" class="text-blue-600 hover:underline">Manage categories</a>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Product type</label>
                        <select name="type" class="w-full border-gray-300 rounded text-sm">
                            <option value="simple" @selected(old('type', $product->type) === 'simple')>Simple</option>
                            <option value="service" @selected(old('type', $product->type) === 'service')>Service (no stock)</option>
                            <option value="bundle" @selected(old('type', $product->type) === 'bundle')>Bundle</option>
                            <option value="variant" @selected(old('type', $product->type) === 'variant')>Variant parent</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">SKU</label>
                        <input type="text" name="sku" value="{{ old('sku', $product->sku) }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Barcode (auto if blank)</label>
                        <input type="text" name="barcode" value="{{ old('barcode', $product->barcode) }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Description</label>
                        <textarea name="description" rows="3" class="w-full border-gray-300 rounded text-sm">{{ old('description', $product->description) }}</textarea>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Image</label>
                        @if ($product->image)
                            <div class="flex items-center gap-3 mb-2">
                                <img src="{{ Storage::url($product->image) }}" class="w-20 h-20 rounded object-cover">
                                <label class="text-xs text-red-600 flex items-center gap-1">
                                    <input type="checkbox" name="remove_image" value="1"> Remove current image
                                </label>
                            </div>
                        @endif
                        <input type="file" name="image" accept="image/*" class="text-sm" />
                        <div class="text-xs text-gray-500 mt-1">JPG/PNG/WebP, max 4 MB</div>
                    </div>
                </div>

                {{-- PRICING --}}
                <div x-show="tab==='pricing'" x-cloak class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4"
                     x-data="{ price: {{ (float) old('price_usd', $product->price_usd ?: 0) }}, cost: {{ (float) old('cost_usd', $product->cost_usd ?: 0) }} }">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Cost (USD)</label>
                        <input type="number" name="cost_usd" step="0.01" min="0" x-model.number="cost"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Retail Price (USD) *</label>
                        <input type="number" name="price_usd" step="0.01" min="0" x-model.number="price" required
                               class="w-full border-gray-300 rounded text-sm" />
                        <div class="text-xs mt-1" x-show="cost > 0">
                            Margin: <span class="font-semibold" x-text="((price - cost) / Math.max(price, 0.0001) * 100).toFixed(1) + '%'"></span>
                            (Profit: $<span x-text="(price - cost).toFixed(2)"></span>)
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Wholesale Price (USD)</label>
                        <input type="number" name="wholesale_price_usd" step="0.01" min="0"
                               value="{{ old('wholesale_price_usd', $product->wholesale_price_usd) }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">VIP Price (USD)</label>
                        <input type="number" name="vip_price_usd" step="0.01" min="0"
                               value="{{ old('vip_price_usd', $product->vip_price_usd) }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>

                    <div class="md:col-span-2 bg-gray-50 p-3 rounded text-xs">
                        LBP equivalent is auto-derived from the current exchange rate. Override here only if you want to lock a manual LBP price.
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">LBP Price (manual override)</label>
                        <input type="number" name="price_lbp" step="1000" min="0"
                               value="{{ old('price_lbp', $product->price_lbp) }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div class="flex items-end">
                        <label class="text-sm flex items-center gap-2">
                            <input type="checkbox" name="force_lbp_price" value="1" @checked(old('force_lbp_price', $product->force_lbp_price))>
                            Use the LBP price above (ignore exchange rate)
                        </label>
                    </div>
                </div>

                {{-- STOCK --}}
                <div x-show="tab==='stock'" x-cloak class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2 flex items-center gap-2">
                        <input type="checkbox" name="track_stock" value="1" id="track_stock" @checked(old('track_stock', $product->track_stock))>
                        <label for="track_stock" class="text-sm">Track stock for this product</label>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Current Stock</label>
                        <input type="number" name="stock_qty" step="0.0001" min="0"
                               value="{{ old('stock_qty', $product->stock_qty) }}"
                               class="w-full border-gray-300 rounded text-sm" {{ $isEdit ? 'readonly' : '' }} />
                        @if ($isEdit)
                            <div class="text-xs text-gray-500 mt-1">Use the stock adjustment form below to change stock with audit trail.</div>
                        @endif
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Min Stock (low-stock alert)</label>
                        <input type="number" name="min_stock" step="0.0001" min="0"
                               value="{{ old('min_stock', $product->min_stock) }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Max Stock</label>
                        <input type="number" name="max_stock" step="0.0001" min="0"
                               value="{{ old('max_stock', $product->max_stock) }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Unit</label>
                        <select name="unit" class="w-full border-gray-300 rounded text-sm">
                            @foreach (['pcs','kg','g','L','mL','box','pair','set','m'] as $u)
                                <option value="{{ $u }}" @selected(old('unit', $product->unit) === $u)>{{ $u }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Shelf / Location</label>
                        <input type="text" name="location" value="{{ old('location', $product->location) }}"
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                </div>

                {{-- TAX --}}
                <div x-show="tab==='tax'" x-cloak class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Tax rate</label>
                        <select name="tax_id" class="w-full border-gray-300 rounded text-sm">
                            <option value="">No tax</option>
                            @foreach ($taxes as $t)
                                <option value="{{ $t->id }}" @selected(old('tax_id', $product->tax_id ?? $defaultTaxId) == $t->id)>
                                    {{ $t->name }} ({{ number_format((float) $t->rate * 100, 2) }}%)
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-end">
                        <label class="text-sm flex items-center gap-2">
                            <input type="checkbox" name="is_taxable" value="1" @checked(old('is_taxable', $product->is_taxable))>
                            Tax applies to this product
                        </label>
                    </div>

                    <div class="flex items-center">
                        <label class="text-sm flex items-center gap-2">
                            <input type="checkbox" name="allow_discount" value="1" @checked(old('allow_discount', $product->allow_discount))>
                            Allow discount at POS
                        </label>
                    </div>
                    <div class="flex items-center">
                        <label class="text-sm flex items-center gap-2">
                            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active))>
                            Active (sellable in POS)
                        </label>
                    </div>
                </div>

                <div class="border-t p-4 flex justify-end gap-2">
                    <a href="{{ route('products.index') }}" class="px-4 py-2 text-sm bg-gray-100 rounded">Cancel</a>
                    <button class="px-4 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700">
                        {{ $isEdit ? 'Save Changes' : 'Create Product' }}
                    </button>
                </div>
            </div>
        </form>

        {{-- Manual stock adjustment (edit only) --}}
        @if ($isEdit)
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h3 class="font-semibold mb-3">Stock Adjustment</h3>
                <form method="POST" action="{{ route('products.adjust-stock', $product) }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end text-sm">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Action</label>
                        <select name="action" class="w-full border-gray-300 rounded text-sm" required>
                            <option value="add">Add to stock</option>
                            <option value="remove">Remove from stock</option>
                            <option value="set">Set absolute</option>
                            <option value="count">Stock count</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Quantity</label>
                        <input type="number" name="qty" step="0.0001" min="0" value="0" required
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Reason</label>
                        <input type="text" name="reason" placeholder="e.g. supplier delivery, breakage, count correction" required
                               class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div class="md:col-span-4 text-right">
                        <button class="px-4 py-2 bg-orange-600 text-white rounded text-sm hover:bg-orange-700">Adjust Stock</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</x-app-layout>
