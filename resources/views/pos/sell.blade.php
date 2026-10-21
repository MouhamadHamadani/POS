<x-pos-layout>
    <div class="p-3" x-data="posApp()" x-init="init()">
        <div class="max-w-[1600px] mx-auto">

            @if (session('success'))
                <div class="mb-3 p-3 bg-green-50 text-green-800 rounded">{{ session('success') }}</div>
            @endif

            <div x-show="errorMsg && !showPayment" x-cloak
                 x-transition.opacity
                 class="mb-3 p-3 bg-red-50 text-red-700 rounded text-sm flex justify-between items-center">
                <span x-text="errorMsg"></span>
                <button type="button" @click="errorMsg=''" class="text-red-500">×</button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">

                {{-- Left: products --}}
                <div class="lg:col-span-7 space-y-3">
                    <div class="bg-white rounded-lg shadow-sm p-3">
                        <div class="flex gap-2 mb-1">
                            <input type="text" x-model="search" @input.debounce.250ms="filter()"
                                   placeholder="{{ __('pos.ui.search_placeholder') }}"
                                   @keydown.enter="onBarcodeEnter($event)"
                                   x-ref="search" class="flex-1 border-gray-300 rounded-md text-sm" autofocus />
                            <button type="button" @click="activeCategory=null;filter()"
                                    class="px-3 py-1 text-xs bg-gray-100 rounded">{{ __('common.all') }}</button>
                        </div>

                        <p class="text-[11px] text-gray-400 mb-3">
                            {{ __('pos.ui.tip') }}
                        </p>

                        {{-- Category tabs --}}
                        <div class="flex flex-wrap gap-2 mb-3">
                            <template x-for="cat in categories" :key="cat.id">
                                <button type="button" @click="activeCategory = cat.id; filter()"
                                        :class="activeCategory === cat.id ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-700'"
                                        class="px-3 py-1 text-xs rounded">
                                    <span x-text="cname(cat)"></span>
                                </button>
                            </template>
                        </div>

                        {{-- Product grid --}}
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 max-h-[60vh] overflow-y-auto">
                            <template x-for="p in filtered" :key="p.id">
                                <button type="button" @click="addToCart(p)"
                                        :disabled="p.track_stock && p.stock_qty <= 0"
                                        class="p-2 bg-white border rounded-lg hover:bg-brand-50 hover:border-brand-300 text-start disabled:opacity-40 disabled:cursor-not-allowed">
                                    <div class="text-xs font-medium text-gray-900 line-clamp-2" x-text="pname(p)"></div>
                                    <div class="text-[11px] text-gray-500 mt-0.5" x-text="p.sku || p.barcode || ''"></div>
                                    <div class="mt-1 text-sm font-bold text-brand-700"><x-ltr>$<span x-text="priceFor(p).toFixed(2)"></span></x-ltr></div>
                                    <div class="text-[11px] text-gray-500" x-text="t('stock_line', { qty: Number(p.stock_qty).toFixed(0), unit: unitLabel(p.unit) })"></div>
                                </button>
                            </template>
                            <div x-show="filtered.length === 0" class="col-span-full text-center text-gray-500 py-6 text-sm">
                                {{ __('pos.ui.no_products') }}
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Right: cart + totals --}}
                <div class="lg:col-span-5">
                    <div class="bg-white rounded-lg shadow-sm">
                        <div class="p-3 border-b flex flex-wrap justify-between items-center gap-2">
                            <div class="flex items-center gap-2">
                                <h3 class="font-medium">{{ __('pos.ui.cart') }} (<span x-text="cart.length"></span>)</h3>
                                <span x-show="priceTier !== 'retail'" x-cloak
                                      :class="priceTier === 'vip' ? 'bg-purple-100 text-purple-800' : 'bg-amber-100 text-amber-800'"
                                      class="px-1.5 py-0.5 rounded text-[10px] font-bold tracking-wide uppercase"
                                      x-text="t(priceTier)"></span>
                            </div>

                            {{-- Price list. Customer selection sets it; F7 cycles it. --}}
                            <div class="inline-flex rounded border border-gray-300 overflow-hidden text-xs" role="group"
                                 :aria-label="t('price_list')"
                                 :title="canChangeTier ? t('price_list') + ' (F7)' : t('tier_locked')">
                                <template x-for="tr in tiers" :key="tr">
                                    <button type="button" @click="setTier(tr)" :disabled="!canChangeTier"
                                            :aria-pressed="priceTier === tr"
                                            :class="priceTier === tr ? 'bg-brand-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                                            class="px-2.5 py-1 disabled:cursor-not-allowed disabled:opacity-60"
                                            x-text="t(tr)"></button>
                                </template>
                            </div>

                            {{-- On-site or an online order (WhatsApp / Instagram / ... rung up by hand).
                                 Bookkeeping only: no price, stock or tax rule depends on it. The server
                                 forces on-site when the feature is off (Sale::resolveChannel). --}}
                            @feature('sales_channels')
                                <div class="flex items-center gap-1">
                                    <div class="inline-flex rounded border border-gray-300 overflow-hidden text-xs" role="group"
                                         aria-label="{{ __('pos.ui.channel') }}" title="{{ __('pos.ui.channel') }}">
                                        @foreach (\App\Models\Sale::CHANNELS as $ch)
                                            <button type="button" @click="setChannel('{{ $ch }}')"
                                                    :aria-pressed="channel === '{{ $ch }}'"
                                                    :class="channel === '{{ $ch }}' ? 'bg-brand-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                                                    class="px-2.5 py-1">{{ \App\Support\EnumLabel::get('sale_channel', $ch) }}</button>
                                        @endforeach
                                    </div>
                                    <select x-show="channel === 'online'" x-cloak x-model="channelSource"
                                            aria-label="{{ __('pos.ui.channel_source') }}"
                                            class="border-gray-300 rounded text-xs py-1">
                                        <option value="">{{ __('pos.ui.channel_source') }}</option>
                                        @foreach (\App\Models\Sale::CHANNEL_SOURCES as $src)
                                            <option value="{{ $src }}">{{ \App\Support\EnumLabel::get('sale_channel_source', $src) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endfeature

                            <button type="button" @click="clearCart()" x-show="cart.length"
                                    class="text-xs text-red-600 hover:underline">{{ __('pos.ui.clear') }}</button>
                        </div>

                        {{-- Customer: "+ Add customer" until one is attached, then a chip.
                             Hidden without the loyalty feature; the search and
                             quick-add endpoints behind it are gated too. --}}
                        @feature('loyalty')
                        <div class="px-3 py-2 border-b">
                            <button type="button" x-show="!customer" @click="openCustomerModal()"
                                    class="w-full py-1.5 text-sm text-brand-700 border border-dashed border-brand-300 rounded hover:bg-brand-50">
                                {{ __('pos.ui.add_customer') }} (F3)
                            </button>

                            <div x-show="customer" x-cloak class="flex items-start gap-2 bg-brand-50 rounded p-2">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium truncate" x-text="customer?.name"></div>
                                    <div class="text-xs text-gray-600 flex flex-wrap gap-x-2">
                                        <span x-show="customer?.phone" x-text="customer?.phone"></span>
                                        <span class="uppercase tracking-wide" x-text="t(customer?.customer_group)"></span>
                                        <span x-show="Number(customer?.balance) > 0" class="text-red-600 font-medium"
                                              x-text="t('owes', { amount: '$' + Number(customer?.balance).toFixed(2) })"></span>
                                    </div>
                                </div>
                                <button type="button" @click="openCustomerModal()"
                                        class="text-xs text-brand-700 hover:underline shrink-0">{{ __('pos.ui.change_customer') }}</button>
                                <button type="button" @click="removeCustomer()" title="{{ __('pos.ui.remove_customer') }}"
                                        class="text-red-500 text-sm leading-none shrink-0">&times;</button>
                            </div>
                        </div>
                        @endfeature

                        <div class="divide-y max-h-[40vh] overflow-y-auto">
                            <template x-for="(line, idx) in cart" :key="line.product_id">
                                <div class="p-2 flex items-center gap-2 text-sm" :class="{ 'bg-brand-50': idx === lastScannedIndex }">
                                    <div class="flex-1 min-w-0">
                                        <button type="button" @click="openLineEditor(idx)" :title="t('discount') + ' (F8)'"
                                                class="font-medium text-start hover:underline" x-text="line.name"></button>
                                        <div class="text-xs text-gray-500">
                                            <span dir="ltr" class="inline-block">
                                                <x-ltr>$<span x-text="Number(line.unit_price).toFixed(2)"></span></x-ltr>
                                                <span x-show="line.unit_price !== line.retail_price" class="line-through text-gray-400"><x-ltr>$<span x-text="Number(line.retail_price).toFixed(2)"></span></x-ltr></span>
                                                × <span x-text="line.qty"></span>
                                            </span>
                                        </div>
                                        <div x-show="lineDiscountText(idx)" x-cloak class="text-xs text-green-700">
                                            <span dir="ltr" class="inline-block" x-text="lineDiscountText(idx)"></span>
                                        </div>
                                        <div x-show="belowCost(idx)" x-cloak class="text-xs text-amber-600" x-text="t('below_cost')"></div>
                                    </div>
                                    <div class="flex gap-1 items-center">
                                        <button type="button" @click="decQty(idx)" class="w-6 h-6 bg-gray-100 rounded">-</button>
                                        <input type="number" x-model.number="line.qty" @change="onQtyChange(idx)" min="0.0001" step="1"
                                               class="w-12 text-center border-gray-300 rounded text-sm py-0.5" />
                                        <button type="button" @click="incQty(idx)" class="w-6 h-6 bg-gray-100 rounded">+</button>
                                    </div>
                                    <div class="w-16 text-end font-medium"><x-ltr>$<span x-text="lineCalc(idx).total.toFixed(2)"></span></x-ltr></div>
                                    <button type="button" @click="removeLine(idx)" class="text-red-500 text-xs">×</button>
                                </div>
                            </template>
                            <div x-show="cart.length === 0" class="p-6 text-center text-sm text-gray-500">
                                {{ __('pos.ui.cart_empty') }}
                            </div>
                        </div>

                        <div class="p-3 border-t space-y-1 text-sm">
                            <div class="flex justify-between items-center">
                                <span class="text-gray-500">{{ __('purchases.subtotal') }}
                                    <button type="button" @click="openCartEditor()" :disabled="cart.length === 0"
                                            :title="t('cart_discount') + ' (Shift+F8)'"
                                            class="ms-2 px-1.5 py-0.5 text-xs text-brand-700 border border-brand-300 rounded hover:bg-brand-50 disabled:opacity-40 disabled:cursor-not-allowed"
                                            x-text="t('discount')"></button>
                                </span>
                                <span><x-ltr>$<span x-text="totals.subtotal.toFixed(2)"></span></x-ltr></span>
                            </div>
                            <div class="flex justify-between text-gray-500"><span x-text="t('discount')"></span><span><x-ltr>-$<span x-text="totals.discount.toFixed(2)"></span></x-ltr></span></div>
                            <div x-show="totals.cartDisc > 0" x-cloak class="flex justify-between text-gray-500 text-xs ps-3">
                                <span>
                                    <span x-text="t('of_which_cart') + (cartDiscount.type === 'pct' ? ' (' + Number(cartDiscount.value) + '%)' : '')"></span>
                                    <button type="button" @click="clearCartDiscount()" :title="t('remove_discount')" class="text-red-500 leading-none">&times;</button>
                                </span>
                                <span><x-ltr>-$<span x-text="totals.cartDisc.toFixed(2)"></span></x-ltr></span>
                            </div>
                            <div class="flex justify-between text-gray-500"><span x-text="vatLabel"></span><span><x-ltr>$<span x-text="totals.tax.toFixed(2)"></span></x-ltr></span></div>
                            <div class="flex justify-between text-lg font-bold border-t pt-2 mt-2">
                                <span>{{ __('dashboard.total') }}</span>
                                <span class="text-brand-700"><x-ltr>$<span x-text="totals.total.toFixed(2)"></span></x-ltr></span>
                            </div>
                            <div class="flex justify-between text-xs text-gray-500">
                                <span>{{ __('pos.ui.lbp_equiv') }}</span>
                                <span x-text="formatLbp(totals.total * exchangeRate)"></span>
                            </div>

                            <div x-show="issues.length" x-cloak class="mt-2 p-2 bg-red-50 text-red-700 rounded text-xs space-y-0.5">
                                <template x-for="(m, mi) in issues" :key="mi"><div x-text="m"></div></template>
                                <div class="font-medium" x-text="t('fix_discounts')"></div>
                            </div>

                            <button type="button" @click="openPayment()" :disabled="cart.length === 0 || issues.length > 0"
                                    class="w-full mt-3 py-2 bg-green-600 text-white rounded hover:bg-green-700 disabled:bg-gray-300 disabled:cursor-not-allowed font-medium">
                                {{ __('pos.ui.pay') }} (F4)
                            </button>

                            <div class="flex gap-2 mt-2">
                                <button type="button" @click="openHold()" :disabled="cart.length === 0"
                                        class="flex-1 py-2 bg-amber-500 text-white rounded text-sm hover:bg-amber-600 disabled:bg-gray-300 disabled:cursor-not-allowed">
                                    {{ __('pos.ui.hold') }} (F5)
                                </button>
                                <button type="button" @click="openHeldList()"
                                        class="flex-1 py-2 bg-slate-700 text-white rounded text-sm hover:bg-slate-800">
                                    {{ __('pos.ui.recall') }} <span x-show="heldCount > 0" class="text-xs bg-amber-400 text-slate-900 rounded px-1 ms-1" x-text="heldCount"></span> (F6)
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Customer picker: typeahead over existing customers, plus inline quick-add --}}
            <div x-show="showCustomerModal" x-cloak @keydown.escape.window="showCustomerModal=false"
                 class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-5">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-lg font-bold">{{ __('pos.ui.customer') }}</h3>
                        <button type="button" @click="showCustomerModal=false" class="text-gray-400 hover:text-gray-700 text-xl">&times;</button>
                    </div>

                    <div class="space-y-3 text-sm">
                        {{-- Search existing --}}
                        <div x-show="!showNewCustomer">
                            <label class="block text-xs font-medium text-gray-600">{{ __('pos.ui.search_customer') }}</label>
                            <input type="text" x-ref="customerSearch" x-model="customerQuery"
                                   @input="onCustomerQuery()" maxlength="60"
                                   placeholder="{{ __('pos.ui.min_chars') }}" dir="auto"
                                   class="w-full border-gray-300 rounded mt-1 text-sm" />

                            <div class="mt-2 max-h-56 overflow-y-auto divide-y border rounded"
                                 x-show="customerResults.length">
                                <template x-for="c in customerResults" :key="c.id">
                                    <button type="button" @click="selectCustomer(c)"
                                            class="w-full text-start p-2 hover:bg-brand-50">
                                        <div class="font-medium" x-text="c.name"></div>
                                        <div class="text-xs text-gray-500 flex flex-wrap gap-x-2">
                                            <span x-show="c.phone" x-text="c.phone"></span>
                                            <span class="uppercase" x-text="t(c.customer_group)"></span>
                                            <span x-show="Number(c.balance) > 0" class="text-red-600"
                                                  x-text="t('owes', { amount: '$' + Number(c.balance).toFixed(2) })"></span>
                                        </div>
                                    </button>
                                </template>
                            </div>

                            <div x-show="customerSearching" class="text-xs text-gray-500 mt-2">{{ __('pos.ui.searching') }}</div>
                            <div x-show="!customerSearching && customerQuery.trim().length >= 2 && customerResults.length === 0"
                                 class="text-xs text-gray-500 mt-2">
                                {{ __('pos.ui.no_match') }}
                            </div>

                            <button type="button" @click="startNewCustomer()"
                                    class="mt-3 text-sm text-brand-700 hover:underline">{{ __('pos.ui.new_customer') }}</button>
                        </div>

                        {{-- Quick-add --}}
                        <div x-show="showNewCustomer" x-cloak class="space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600">{{ __('common.name') }} *</label>
                                <input type="text" dir="auto" x-ref="newCustomerName" x-model="newCustomer.name" maxlength="120"
                                       class="w-full border-gray-300 rounded mt-1 text-sm" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600">{{ __('pos.ui.phone_optional') }}</label>
                                <input type="text" dir="ltr" x-model="newCustomer.phone" maxlength="30"
                                       placeholder="03 123 456"
                                       class="w-full border-gray-300 rounded mt-1 text-sm" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600">{{ __('customers.group') }}</label>
                                <select x-model="newCustomer.customer_group" class="w-full border-gray-300 rounded mt-1 text-sm">
                                    <option value="retail">{{ __('pos.ui.retail') }}</option>
                                    <option value="wholesale">{{ __('pos.ui.wholesale') }}</option>
                                    <option value="vip">{{ __('pos.ui.vip') }}</option>
                                </select>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" @click="showNewCustomer=false; customerError=''"
                                        class="px-3 py-2 bg-gray-100 rounded text-sm">{{ __('pos.ui.back_to_search') }}</button>
                                <button type="button" @click="saveNewCustomer()" :disabled="customerProcessing"
                                        class="flex-1 py-2 bg-brand-600 text-white rounded font-medium hover:bg-brand-700 disabled:bg-gray-300 disabled:cursor-not-allowed">
                                    <span x-show="!customerProcessing">{{ __('pos.ui.create_attach') }}</span>
                                    <span x-show="customerProcessing">{{ __('pos.ui.saving') }}</span>
                                </button>
                            </div>
                        </div>

                        <div x-show="customerError" x-cloak class="text-sm text-red-600 bg-red-50 p-2 rounded" x-text="customerError"></div>
                    </div>
                </div>
            </div>

            {{-- Hold modal: optional label/notes before sending the cart to the held-sales bin --}}
            <div x-show="showHoldModal" x-cloak @keydown.escape.window="showHoldModal=false"
                 class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-5">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-lg font-bold">{{ __('pos.ui.hold_this_sale') }}</h3>
                        <button type="button" @click="showHoldModal=false" class="text-gray-400 hover:text-gray-700 text-xl">×</button>
                    </div>
                    <p class="text-sm text-gray-600 mb-3">{{ __('pos.ui.hold_help') }}</p>
                    <div class="space-y-3 text-sm">
                        <div>
                            <label class="block text-xs font-medium text-gray-600">{{ __('pos.ui.label_optional') }}</label>
                            <input type="text" dir="auto" x-model="holdDraft.label" maxlength="80"
                                   placeholder="{{ __('pos.ui.label_placeholder') }}"
                                   class="w-full border-gray-300 rounded mt-1 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600">{{ __('shifts.notes_optional') }}</label>
                            <textarea dir="auto" x-model="holdDraft.notes" rows="2" maxlength="500"
                                      class="w-full border-gray-300 rounded mt-1 text-sm"></textarea>
                        </div>
                        <div x-show="errorMsg" x-cloak class="text-sm text-red-600 bg-red-50 p-2 rounded" x-text="errorMsg"></div>
                        <button type="button" @click="submitHold()" :disabled="holdProcessing"
                                class="w-full py-3 bg-amber-500 text-white rounded font-bold hover:bg-amber-600 disabled:bg-gray-300 disabled:cursor-not-allowed">
                            <span x-show="!holdProcessing">{{ __('pos.ui.hold_sale') }}</span>
                            <span x-show="holdProcessing">{{ __('pos.ui.holding') }}</span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Held sales list (recall) --}}
            <div x-show="showHeldList" x-cloak @keydown.escape.window="showHeldList=false"
                 class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full p-5 max-h-[80vh] overflow-y-auto">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-lg font-bold">{{ __('pos.ui.held_sales') }}</h3>
                        <button type="button" @click="showHeldList=false" class="text-gray-400 hover:text-gray-700 text-xl">×</button>
                    </div>
                    <div x-show="heldList.length === 0" class="py-8 text-center text-sm text-gray-500">{{ __('pos.ui.no_held') }}</div>
                    <div class="space-y-2">
                        <template x-for="h in heldList" :key="h.id">
                            <div class="border rounded p-3 flex items-center gap-3 text-sm">
                                <div class="flex-1">
                                    <div class="font-medium">
                                        <span x-text="h.label || t('hold_n', { n: h.id })"></span>
                                        <span x-show="h.customer" class="text-xs text-gray-500" x-text="'· ' + (h.customer?.name)"></span>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        <span x-text="t('n_items', { n: h.item_count })"></span>
                                        · <x-ltr>$<span x-text="Number(h.subtotal).toFixed(2)"></span></x-ltr>
                                        · <span x-text="new Date(h.held_at).toLocaleTimeString('en-US')"></span>
                                    </div>
                                    <div x-show="h.notes" class="text-xs italic text-gray-500" x-text="h.notes"></div>
                                </div>
                                <button type="button" @click="recallHold(h.id)"
                                        class="px-3 py-1.5 bg-green-600 text-white rounded text-xs hover:bg-green-700">{{ __('pos.ui.recall_one') }}</button>
                                <button type="button" @click="discardHold(h.id)"
                                        class="px-3 py-1.5 bg-red-600 text-white rounded text-xs hover:bg-red-700">{{ __('pos.ui.discard') }}</button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Discount editor: one modal for a single cart line (target 'line') or the whole cart ('cart').
                 Esc closes it and focus goes back to the search box so barcode scanning keeps working. --}}
            <div x-show="editor.open" x-cloak @keydown.escape.window="editor.open && closeEditor()"
                 class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-sm w-full p-5">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-lg font-bold"
                            x-text="editor.target === 'cart' ? t('cart_discount') : t('discount_for', { name: editorLine()?.name || '' })"></h3>
                        <button type="button" @click="closeEditor()" class="text-gray-400 hover:text-gray-700 text-xl">&times;</button>
                    </div>

                    <div x-show="editorBlocked()" x-cloak class="mb-3 p-2 bg-amber-50 text-amber-800 rounded text-sm" x-text="t('not_allowed')"></div>

                    <div class="space-y-3 text-sm">
                        <div class="flex gap-2 items-stretch">
                            <div class="inline-flex rounded border border-gray-300 overflow-hidden" role="group">
                                <button type="button" @click="editor.type = 'pct'" :disabled="editorBlocked()" :title="t('by_percent')"
                                        :class="editor.type === 'pct' ? 'bg-brand-600 text-white' : 'bg-white text-gray-700'"
                                        class="px-3 py-1.5 disabled:opacity-50">%</button>
                                <button type="button" @click="editor.type = 'amount'" :disabled="editorBlocked()" :title="t('by_amount')"
                                        :class="editor.type === 'amount' ? 'bg-brand-600 text-white' : 'bg-white text-gray-700'"
                                        class="px-3 py-1.5 disabled:opacity-50">$</button>
                            </div>
                            <input type="number" x-ref="editorInput" x-model="editor.value" min="0" step="0.01" dir="ltr"
                                   :disabled="editorBlocked()" :aria-label="t('value')"
                                   @keydown.enter.prevent="applyEditor()"
                                   class="flex-1 min-w-0 border-gray-300 rounded text-sm disabled:bg-gray-100" />
                        </div>

                        <div x-show="maxDiscountPct < 100" class="text-xs text-gray-500" x-text="t('limit_hint', { limit: maxDiscountPct })"></div>
                        <div x-show="editor.error" x-cloak class="text-sm text-red-600 bg-red-50 p-2 rounded" x-text="editor.error"></div>

                        <div class="flex gap-2">
                            <button type="button" @click="removeEditorDiscount()" :disabled="editorBlocked()"
                                    class="px-3 py-2 bg-gray-100 rounded text-sm disabled:opacity-50" x-text="t('remove_discount')"></button>
                            <button type="button" @click="applyEditor()" :disabled="editorBlocked()"
                                    class="flex-1 py-2 bg-brand-600 text-white rounded font-medium hover:bg-brand-700 disabled:bg-gray-300 disabled:cursor-not-allowed"
                                    x-text="t('apply')"></button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Payment modal. Split shows every field at once, so on a short screen it scrolls
                 inside itself rather than clipping the header and the Complete button. --}}
            <div x-show="showPayment" x-cloak @keydown.escape.window="showPayment=false"
                 class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-5 max-h-full overflow-y-auto">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-lg font-bold">{{ __('pos.ui.payment') }}</h3>
                        <button type="button" @click="showPayment=false" class="text-gray-400 hover:text-gray-700 text-xl">×</button>
                    </div>

                    <div class="text-center py-3 bg-brand-50 rounded mb-4">
                        <div class="text-sm text-gray-600">{{ __('pos.ui.total_due') }}</div>
                        <div class="text-3xl font-bold text-brand-700"><x-ltr>$<span x-text="totals.total.toFixed(2)"></span></x-ltr></div>
                        <div class="text-xs text-gray-500" x-text="formatLbp(totals.total * exchangeRate)"></div>
                    </div>

                    <div class="space-y-3 text-sm">
                        {{-- How the customer pays. The buttons only choose which fields are shown (and pre-fill the
                             obvious amounts); what is SENT is the amounts, and the server derives the method. --}}
                        <div class="grid grid-cols-4 gap-1" role="group" aria-label="{{ __('customers.method') }}">
                            @foreach (['cash', 'card', 'wish', 'split'] as $mode)
                                <button type="button" @click="setPayMode('{{ $mode }}')"
                                        :aria-pressed="payMode === '{{ $mode }}'"
                                        :class="payMode === '{{ $mode }}' ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                                        class="py-2 rounded border text-sm font-medium">{{ __("pos.ui.pay_$mode") }}</button>
                            @endforeach
                        </div>

                        <div x-show="['cash','split'].includes(payMode)">
                            <label class="block text-xs font-medium text-gray-600">{{ __('pos.ui.cash_usd_tendered') }}</label>
                            <input type="number" dir="ltr" step="0.01" min="0" x-ref="cashUsd" x-model.number="payment.amount_usd" @input="refreshChangePreview()"
                                   class="w-full border-gray-300 rounded mt-1 text-sm" />
                            <div class="flex gap-1 mt-1">
                                <template x-for="d in [1,5,10,20,50,100]">
                                    <button type="button" @click="payment.amount_usd = (payment.amount_usd||0) + d; refreshChangePreview()"
                                            class="text-xs px-2 py-1 bg-gray-100 rounded"><x-ltr>+$<span x-text="d"></span></x-ltr></button>
                                </template>
                            </div>
                        </div>

                        <div x-show="['cash','split'].includes(payMode)">
                            <label class="block text-xs font-medium text-gray-600">{{ __('pos.ui.cash_lbp_tendered') }}</label>
                            <input type="number" dir="ltr" step="1000" min="0" x-model.number="payment.amount_lbp" @input="refreshChangePreview()"
                                   class="w-full border-gray-300 rounded mt-1 text-sm" />
                            <div class="flex gap-1 mt-1 flex-wrap">
                                <template x-for="d in [50000, 100000, 250000, 500000, 1000000]">
                                    <button type="button" @click="payment.amount_lbp = (payment.amount_lbp||0) + d; refreshChangePreview()"
                                            class="text-xs px-2 py-1 bg-gray-100 rounded" x-text="`+${(d/1000).toFixed(0)}k`"></button>
                                </template>
                            </div>
                        </div>

                        <div x-show="['card','split'].includes(payMode)">
                            <label class="block text-xs font-medium text-gray-600">{{ __('pos.ui.card_amount') }}</label>
                            <div class="flex gap-1 mt-1">
                                <input type="number" dir="ltr" step="0.01" min="0" x-model.number="payment.amount_card" @input="refreshChangePreview()"
                                       class="flex-1 border-gray-300 rounded text-sm" />
                                <button type="button" x-show="payMode === 'split'" @click="fillRemaining('amount_card')"
                                        class="text-xs px-2 py-1 bg-gray-100 rounded whitespace-nowrap">{{ __('pos.ui.fill_remaining') }}</button>
                            </div>
                            <label class="block text-xs font-medium text-gray-600 mt-2">{{ __('pos.ui.card_type_ref') }}</label>
                            <div class="flex gap-2">
                                <select x-model="payment.card_type" class="border-gray-300 rounded text-sm">
                                    <option value="Visa">Visa</option><option value="MasterCard">MasterCard</option>
                                    <option value="Amex">Amex</option><option value="Other">{{ __('enums.payment_method.other') }}</option>
                                </select>
                                <input type="text" x-model="payment.card_reference" dir="ltr" placeholder="{{ __('pos.ui.approval_ref') }}"
                                       class="flex-1 border-gray-300 rounded text-sm" />
                            </div>
                        </div>

                        {{-- Wish is bookkeeping only: the cashier takes the payment in the Wish app, this records it. --}}
                        <div x-show="['wish','split'].includes(payMode)">
                            <label class="block text-xs font-medium text-gray-600">{{ __('pos.ui.wish_amount') }}</label>
                            <div class="flex gap-1 mt-1">
                                <input type="number" dir="ltr" step="0.01" min="0" x-model.number="payment.amount_wish" @input="refreshChangePreview()"
                                       class="flex-1 border-gray-300 rounded text-sm" />
                                <button type="button" x-show="payMode === 'split'" @click="fillRemaining('amount_wish')"
                                        class="text-xs px-2 py-1 bg-gray-100 rounded whitespace-nowrap">{{ __('pos.ui.fill_remaining') }}</button>
                            </div>
                            <label class="block text-xs font-medium text-gray-600 mt-2">{{ __('pos.ui.wish_ref') }}</label>
                            <input type="text" x-model="payment.wish_reference" dir="ltr" maxlength="100"
                                   class="w-full border-gray-300 rounded text-sm" />
                        </div>

                        {{-- Total / Paid so far / Remaining (or the change owed). --}}
                        <div class="bg-gray-50 p-2 rounded text-xs space-y-1">
                            <div class="flex justify-between"><span>{{ __('pos.ui.total_due') }}</span><span><x-ltr>$<span x-text="totals.total.toFixed(2)"></span></x-ltr></span></div>
                            <div class="flex justify-between"><span>{{ __('pos.ui.paid_so_far') }}</span><span><x-ltr>$<span x-text="tenderedUsd().toFixed(2)"></span></x-ltr></span></div>
                            <div class="flex justify-between font-semibold" :class="changeUsd() >= -0.005 && !noncashExceeds() ? 'text-green-700' : 'text-red-600'">
                                <span x-text="changeUsd() > 0.005 && !noncashExceeds() ? t('change_owed') : t('remaining')"></span>
                                <span><x-ltr>$<span x-text="(changeUsd() > 0.005 && !noncashExceeds() ? changeUsd() : remainingUsd()).toFixed(2)"></span></x-ltr></span>
                            </div>
                        </div>
                        <div x-show="noncashExceeds()" x-cloak class="text-xs text-red-600" x-text="t('noncash_exceeds')"></div>
                        <div x-show="cashNotNeeded()" x-cloak class="text-xs text-red-600" x-text="t('cash_not_needed')"></div>

                        {{-- Split-change section: cashier decides how much USD to give back --}}
                        <div x-show="changeUsd() > 0.005 && !noncashExceeds()" class="border border-brand-200 bg-brand-50 p-2 rounded text-xs space-y-2">
                            <div class="flex justify-between items-center">
                                <span class="font-semibold text-brand-800">{{ __('pos.ui.change_to_return') }}</span>
                                <div class="flex gap-1">
                                    <button type="button" @click="setSplit('all_usd')" class="px-2 py-0.5 bg-white border rounded text-[10px]">{{ __('pos.ui.all_usd') }}</button>
                                    <button type="button" @click="setSplit('all_lbp')" class="px-2 py-0.5 bg-white border rounded text-[10px]">{{ __('pos.ui.all_lbp') }}</button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-[11px] text-gray-600">{{ __('pos.ui.give_usd') }}</label>
                                <input type="number" dir="ltr" step="0.01" min="0" :max="changeUsd().toFixed(2)"
                                       x-model.number="payment.change_usd_out" @input="refreshChangePreview()"
                                       class="w-full border-gray-300 rounded text-sm" />
                            </div>
                            <template x-if="changePreview">
                                <div class="space-y-1">
                                    <div class="flex justify-between">
                                        <span>{{ __('pos.ui.usd_portion') }}</span>
                                        <span class="font-medium"><x-ltr>$<span x-text="Number(changePreview.change.change_usd).toFixed(2)"></span></x-ltr></span>
                                    </div>
                                    <template x-if="changePreview.usd_denoms.length">
                                        <div class="text-[10px] text-gray-500 ps-3" x-text="changePreview.usd_denoms.map(d => d.count + ' × ' + d.label).join(' · ')"></div>
                                    </template>
                                    <div class="flex justify-between">
                                        <span>{{ __('pos.ui.lbp_balance') }}</span>
                                        <span class="font-medium" x-text="formatLbp(Number(changePreview.change.change_lbp))"></span>
                                    </div>
                                    <template x-if="changePreview.lbp_denoms.length">
                                        <div class="text-[10px] text-gray-500 ps-3" x-text="changePreview.lbp_denoms.map(d => d.count + ' × ' + d.label).join(' · ')"></div>
                                    </template>
                                </div>
                            </template>
                        </div>

                        <div x-show="errorMsg" x-cloak class="text-sm text-red-600 bg-red-50 p-2 rounded" x-text="errorMsg"></div>

                        <button type="button" @click="submit()" :disabled="!canComplete()"
                                class="w-full py-3 bg-green-600 text-white rounded font-bold hover:bg-green-700 disabled:bg-gray-300 disabled:cursor-not-allowed">
                            <span x-show="!processing">{{ __('pos.ui.complete_sale') }} (F12)</span>
                            <span x-show="processing">{{ __('pos.ui.processing') }}</span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Receipt confirmation --}}
            <div x-show="lastSale" x-cloak class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-sm w-full p-5 text-center">
                    <div class="text-green-600 text-5xl mb-2">✓</div>
                    <h3 class="text-xl font-bold mb-1">{{ __('pos.ui.sale_completed') }}</h3>
                    <div class="text-sm text-gray-500 mb-3" dir="ltr" x-text="lastSale?.receipt_number"></div>
                    <div class="text-3xl font-bold text-brand-700 mb-1"><x-ltr>$<span x-text="Number(lastSale?.total_usd || 0).toFixed(2)"></span></x-ltr></div>
                    <div class="text-xs text-gray-500 mb-1" x-text="formatLbp((lastSale?.total_usd || 0) * exchangeRate)"></div>
                    <template x-if="lastSale && Number(lastSale.change_usd) > 0">
                        <div class="text-sm text-gray-700 mt-2">{{ __('pos.ui.change') }}: <x-ltr>$<span x-text="Number(lastSale.change_usd).toFixed(2)"></span></x-ltr></div>
                    </template>
                    <div class="mt-4 space-y-2">
                        <p x-show="receiptFormat === 'ask'" class="text-sm font-medium text-gray-700">{{ __('pos.ui.choose_format') }}</p>
                        <div class="flex gap-2">
                            {{-- Always both formats, whatever the setting. With "ask" these two are the choice. --}}
                            <button type="button" @click="printLast('thermal')" title="{{ 'Ctrl+P' }}"
                                    class="flex-1 py-2 bg-slate-600 text-white rounded text-sm hover:bg-slate-700"
                                    x-text="t(receiptFormat === 'ask' ? 'small_receipt' : 'print_receipt')"></button>
                            <button type="button" @click="printLast('a4')" title="{{ 'Ctrl+Shift+P' }}"
                                    class="flex-1 py-2 bg-slate-600 text-white rounded text-sm hover:bg-slate-700"
                                    x-text="t(receiptFormat === 'ask' ? 'a4_invoice' : 'print_a4')"></button>
                        </div>
                        <button type="button" @click="lastSale=null"
                                class="w-full py-2 bg-brand-600 text-white rounded">{{ __('pos.ui.new_sale') }}</button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    @push('scripts')
    <script>
        function posApp() {
            return {
                allProducts: @json($products),
                categories: @json($categories),
                exchangeRate: {{ $exchangeRate }},
                lbpStep: {{ $lbpStep }},
                autoPrint: {{ $autoPrint ? 'true' : 'false' }},
                receiptFormat: @json($receiptFormat),   // thermal | a4 | ask (the receipt_format setting)
                a4Silent: @json($a4Silent),             // desktop app with an A4 printer chosen: no dialog
                strings: @json(__('pos.ui')),
                units: @json(__('enums.unit')),
                tiers: ['retail', 'wholesale', 'vip'],
                priceTier: 'retail',        // active price list; the server re-checks it (SaleService::resolvePriceTier)
                channel: 'onsite',          // onsite | online; bookkeeping, resets to onsite after every sale or hold
                channelSource: '',          // whatsapp | facebook | ... (only with online; '' = not specified)
                canChangeTier: @json($canChangeTier),
                showCost: @json($showCost), // managers only: lets the "below cost" warning work
                maxDiscountPct: @json($maxDiscountPct), // effective % of a line's gross; 100 = unlimited
                cartDiscount: { type: 'pct', value: 0 }, // whole-cart discount, split over lines by computeCart()
                editor: { open: false, target: 'line', idx: null, type: 'pct', value: '', error: '' },
                search: '',
                activeCategory: null,
                filtered: [],
                cart: [],
                lastScannedIndex: null, // index of the most recently added/touched cart line
                customer: null,         // the POS customer shape: {id, name, phone, customer_group, balance, ...}
                showCustomerModal: false,
                showNewCustomer: false,
                customerQuery: '',
                customerResults: [],
                customerSearching: false,
                customerProcessing: false,
                customerError: '',
                newCustomer: { name: '', phone: '', customer_group: 'retail' },
                _customerTimer: null,
                showPayment: false,
                showHoldModal: false,
                showHeldList: false,
                holdProcessing: false,
                holdDraft: { label: '', notes: '' },
                heldList: [],
                heldCount: 0,
                processing: false,
                errorMsg: '',
                lastSale: null,
                // Flat, one slot per kind of money. `method` is gone: the server derives it from the amounts.
                payment: { amount_usd: 0, amount_lbp: 0, amount_card: 0, card_type: 'Visa', card_reference: '', amount_wish: 0, wish_reference: '', change_usd_out: null },
                payMode: 'cash',            // cash | card | wish | split: which fields are shown, nothing more
                changePreview: null,
                _previewTimer: null,

                init() {
                    // Alpine runs init() on its own and x-init="init()" runs it again; without
                    // this every key listener below is registered twice (F7 would skip a list).
                    if (this._ready) return;
                    this._ready = true;
                    this.filter();
                    this.refreshHeldCount();
                    window.addEventListener('keydown', (e) => {
                        if (e.key === 'F3') { e.preventDefault(); this.openCustomerModal(); }
                        if (e.key === 'F4') { e.preventDefault(); this.openPayment(); }
                        if (e.key === 'F5') { e.preventDefault(); this.openHold(); }
                        if (e.key === 'F6') { e.preventDefault(); this.openHeldList(); }
                        if (e.key === 'F9') { e.preventDefault(); this.clearCart(); }
                        // F7 price list, F8 discount for the selected line, Shift+F8 whole-cart discount.
                        // Function keys only: a letter key would land in the barcode box.
                        if (e.key === 'F7' && !this.anyModalOpen()) { e.preventDefault(); this.cycleTier(); }
                        if (e.key === 'F8' && !this.anyModalOpen()) {
                            e.preventDefault();
                            if (e.shiftKey) this.openCartEditor(); else this.openLineEditor(this.selectedLine());
                        }
                        if (e.key === 'F12' && this.showPayment) { e.preventDefault(); this.submit(); }
                        // Ctrl+P prints the default format, Ctrl+Shift+P always A4.
                        if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 'P') && this.lastSale?.id) {
                            e.preventDefault();
                            this.printLast(e.shiftKey ? 'a4' : this.defaultFormat());
                        }
                    });
                },

                _csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; },

                // Translated UI string; `:name` placeholders are filled from vars.
                t(key, vars = {}) {
                    let out = this.strings[key] ?? key;
                    for (const [k, v] of Object.entries(vars)) out = out.split(':' + k).join(v);
                    return out;
                },

                // The server's error shape is {error: "text"} or {error: {field: ["text"]}}.
                errorText(data) {
                    const e = data.error ?? data.errors ?? data.message;
                    if (typeof e === 'string') return e;
                    if (e && typeof e === 'object') return Object.values(e).flat().join(' ');
                    return this.t('generic_error');
                },

                anyModalOpen() {
                    return this.showCustomerModal || this.showHoldModal || this.showHeldList
                        || this.showPayment || this.editor.open || !!this.lastSale;
                },

                async refreshHeldCount() {
                    try {
                        const res = await fetch('/pos/api/holds', { headers: { Accept: 'application/json' } });
                        if (res.ok) {
                            const data = await res.json();
                            this.heldList = data;
                            this.heldCount = data.length;
                        }
                    } catch (e) { /* non-critical */ }
                },

                openCustomerModal() {
                    this.customerQuery = '';
                    this.customerResults = [];
                    this.customerError = '';
                    this.showNewCustomer = false;
                    this.showCustomerModal = true;
                    this.$nextTick(() => this.$refs.customerSearch?.focus());
                },

                onCustomerQuery() {
                    clearTimeout(this._customerTimer);
                    const q = this.customerQuery.trim();
                    if (q.length < 2) {
                        this.customerResults = [];
                        this.customerSearching = false;
                        return;
                    }
                    // Debounced so a cashier typing a phone number doesn't fire
                    // a request per keystroke.
                    this.customerSearching = true;
                    this._customerTimer = setTimeout(() => this.searchCustomers(q), 250);
                },

                async searchCustomers(q) {
                    try {
                        const res = await fetch('/pos/api/customers/search?q=' + encodeURIComponent(q), {
                            headers: { Accept: 'application/json' },
                        });
                        // A stale response from an earlier keystroke must not
                        // overwrite results for what is now in the box.
                        if (q !== this.customerQuery.trim()) return;
                        this.customerResults = res.ok ? await res.json() : [];
                    } catch (e) {
                        this.customerError = this.t('network_error', { msg: e.message });
                        this.customerResults = [];
                    } finally {
                        if (q === this.customerQuery.trim()) this.customerSearching = false;
                    }
                },

                selectCustomer(c) {
                    this.customer = c;
                    this.showCustomerModal = false;
                    this.applyTier(c.customer_group); // the customer's group picks the price list
                },

                removeCustomer() {
                    this.customer = null;
                    this.applyTier('retail');
                },

                startNewCustomer() {
                    // Carry whatever was typed across: a search that found nothing
                    // is usually the new customer's name.
                    this.newCustomer = { name: this.customerQuery.trim(), phone: '', customer_group: 'retail' };
                    this.customerError = '';
                    this.showNewCustomer = true;
                    this.$nextTick(() => this.$refs.newCustomerName?.focus());
                },

                async saveNewCustomer() {
                    if (this.customerProcessing) return;
                    if (!this.newCustomer.name.trim()) {
                        this.customerError = this.t('name_required');
                        return;
                    }
                    this.customerProcessing = true;
                    this.customerError = '';
                    try {
                        const res = await fetch('/pos/api/customers/quick-add', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this._csrf(),
                            },
                            body: JSON.stringify({
                                name: this.newCustomer.name.trim(),
                                phone: this.newCustomer.phone.trim() || null,
                                customer_group: this.newCustomer.customer_group,
                            }),
                        });
                        const data = await res.json();
                        if (!res.ok) {
                            this.customerError = data.message
                                || Object.values(data.errors || {}).flat().join(' ')
                                || this.t('customer_create_failed');
                            return;
                        }
                        this.selectCustomer(data);
                    } catch (e) {
                        this.customerError = this.t('network_error', { msg: e.message });
                    } finally {
                        this.customerProcessing = false;
                    }
                },

                openHold() {
                    if (!this.cart.length) return;
                    this.holdDraft = { label: '', notes: '' };
                    this.errorMsg = '';
                    this.showHoldModal = true;
                },

                async submitHold() {
                    if (this.holdProcessing) return;
                    this.holdProcessing = true;
                    this.errorMsg = '';
                    try {
                        const res = await fetch('/pos/api/holds', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this._csrf(),
                            },
                            body: JSON.stringify({
                                cart: this.cart.map(l => ({
                                    product_id: l.product_id,
                                    qty: l.qty,
                                    discount_pct: l.discount_pct,
                                    discount_amount: l.discount_amount,
                                    note: l.note,
                                })),
                                price_tier: this.priceTier,
                                channel: this.channel,
                                channel_source: this.channelPayload(),
                                cart_discount: Number(this.cartDiscount.value) > 0
                                    ? { type: this.cartDiscount.type, value: Number(this.cartDiscount.value) }
                                    : null,
                                customer_id: this.customer?.id || null,
                                label: this.holdDraft.label || null,
                                notes: this.holdDraft.notes || null,
                            }),
                        });
                        const data = await res.json();
                        if (!res.ok) {
                            this.errorMsg = typeof data.error === 'string' ? data.error : this.t('hold_failed');
                            return;
                        }
                        this.resetSale();
                        this.showHoldModal = false;
                        await this.refreshHeldCount();
                    } catch (e) {
                        this.errorMsg = this.t('network_error', { msg: e.message });
                    } finally {
                        this.holdProcessing = false;
                    }
                },

                async openHeldList() {
                    await this.refreshHeldCount();
                    this.showHeldList = true;
                },

                async recallHold(id) {
                    if (this.cart.length > 0 && !confirm(this.t('confirm_replace_cart'))) return;
                    try {
                        const res = await fetch(`/pos/api/holds/${id}/recall`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this._csrf(),
                            },
                        });
                        const data = await res.json();
                        if (!res.ok) {
                            this.errorMsg = data.error || data.message || this.t('recall_failed');
                            return;
                        }
                        // Reconstitute cart from the held payload. A hold keeps no prices: every
                        // line is re-priced from current product data on the hold's price list
                        // (or the customer's, for a cashier who may not pick one).
                        this.customer = data.customer || null;
                        this.priceTier = this.canChangeTier
                            ? this.normTier(data.price_tier)
                            : this.normTier(this.customer?.customer_group);
                        const cart = [];
                        for (const line of data.cart || []) {
                            const p = this.allProducts.find(pp => pp.id === line.product_id);
                            if (!p) continue;
                            cart.push(this.makeLine(p, Number(line.qty), line));
                        }
                        this.cart = cart;
                        this.cartDiscount = data.cart_discount
                            ? { type: data.cart_discount.type, value: Number(data.cart_discount.value) }
                            : { type: 'pct', value: 0 };
                        this.channel = data.channel === 'online' ? 'online' : 'onsite';
                        this.channelSource = this.channel === 'online' ? (data.channel_source || '') : '';
                        this.lastScannedIndex = null;
                        this.showHeldList = false;
                        await this.refreshHeldCount();
                    } catch (e) {
                        this.errorMsg = this.t('network_error', { msg: e.message });
                    }
                },

                async discardHold(id) {
                    if (!confirm(this.t('confirm_discard_hold'))) return;
                    try {
                        const res = await fetch(`/pos/api/holds/${id}`, {
                            method: 'DELETE',
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this._csrf() },
                        });
                        if (res.ok) await this.refreshHeldCount();
                    } catch (e) { /* ignore */ }
                },

                filter() {
                    const s = this.search.trim().toLowerCase();
                    this.filtered = this.allProducts.filter(p => {
                        if (this.activeCategory && p.category_id !== this.activeCategory) return false;
                        if (!s) return true;
                        return (p.name || '').toLowerCase().includes(s)
                            || (p.name_ar || '').includes(this.search)
                            || (p.sku || '').toLowerCase().includes(s)
                            || (p.barcode || '') === s;
                    });
                },

                onBarcodeEnter(e) {
                    // Strip any non-printable/control prefix the scanner might inject
                    // (some scanners are configured with STX/ENQ prefixes).
                    const raw = (this.search || '').replace(/[\x00-\x1F\x7F]/g, '').trim();
                    if (!raw) return;

                    // Quantity shortcut: after scanning/adding a product, typing a short
                    // number + Enter sets that line's quantity instead of scanning again.
                    // Only safe because lookupBarcode() resolves the same active-product
                    // set as allProducts — a short code missing from it would 404 anyway.
                    if (/^\d{1,4}$/.test(raw)
                        && this.lastScannedIndex !== null
                        && this.cart[this.lastScannedIndex]
                        && !this.allProducts.some(p => p.barcode === raw)) {
                        this.setQty(this.lastScannedIndex, parseInt(raw, 10));
                        this.search = '';
                        this.filter();
                        return;
                    }

                    const hit = this.allProducts.find(p => p.barcode === raw);
                    if (hit) { this.addToCart(hit); this.search = ''; this.filter(); return; }
                    fetch(`/pos/api/barcode?code=${encodeURIComponent(raw)}`, { headers: { Accept: 'application/json' }})
                        .then(r => r.ok ? r.json() : Promise.reject())
                        .then(p => { this.addToCart(p); this.search = ''; this.filter(); })
                        .catch(() => {
                            this.errorMsg = this.t('barcode_not_found', { code: raw });
                            setTimeout(() => { this.errorMsg = ''; }, 3000);
                            this.search = '';
                        });
                },

                addToCart(p) {
                    if (p.track_stock && p.stock_qty <= 0) {
                        this.errorMsg = this.t('out_of_stock', { name: this.pname(p) });
                        setTimeout(() => { this.errorMsg = ''; }, 2500);
                        return;
                    }
                    const existing = this.cart.find(l => l.product_id === p.id);
                    if (existing) {
                        if (!this.canAdd(existing, 1)) {
                            this.errorMsg = this.t('only_in_stock', { qty: p.stock_qty, unit: this.unitLabel(p.unit), name: this.pname(p) });
                            setTimeout(() => { this.errorMsg = ''; }, 2500);
                            return;
                        }
                        existing.qty += 1;
                    } else {
                        this.cart.push(this.makeLine(p));
                    }
                    this.recompute();
                    this.lastScannedIndex = this.cart.findIndex(l => l.product_id === p.id);
                },

                // --- price lists -------------------------------------------------
                // Mirrors Product::priceForGroup: a missing tier price falls back to retail.
                tierPrices(p) {
                    const retail = Number(p.price_usd);
                    return {
                        retail,
                        wholesale: Number(p.wholesale_price_usd ?? retail),
                        vip: Number(p.vip_price_usd ?? retail),
                    };
                },

                priceFor(p) { return this.tierPrices(p)[this.priceTier]; },

                normTier(group) { return this.tiers.includes(group) ? group : 'retail'; },

                // Manual switch (button / F7); customer changes go through applyTier() directly.
                setTier(tier) { if (this.canChangeTier) this.applyTier(tier); },

                cycleTier() {
                    if (!this.canChangeTier) return;
                    this.applyTier(this.tiers[(this.tiers.indexOf(this.priceTier) + 1) % this.tiers.length]);
                },

                // Switch list and re-price every line already in the cart.
                applyTier(tier) {
                    this.priceTier = this.normTier(tier);
                    for (const l of this.cart) l.unit_price = l.tier_prices[this.priceTier];
                    this.recompute();
                },

                // The one place a cart line is built (add, recall) so both price and discount rules stay together.
                makeLine(p, qty = 1, saved = {}) {
                    const tp = this.tierPrices(p);
                    return {
                        product_id: p.id, name: this.pname(p),
                        unit_price: tp[this.priceTier], retail_price: tp.retail, tier_prices: tp,
                        qty,
                        discount_pct: Number(saved.discount_pct || 0),
                        discount_amount: Number(saved.discount_amount || 0),
                        allow_discount: p.allow_discount === undefined ? true : !!p.allow_discount,
                        cost_usd: p.cost_usd == null ? null : Number(p.cost_usd), // managers only
                        tax_rate: p.tax ? Number(p.tax.rate) : 0, is_taxable: !!p.is_taxable,
                        tax_inclusive: p.tax ? !!p.tax.is_inclusive : false,
                        track_stock: !!p.track_stock,
                        stock_available: Number(p.stock_qty),
                        unit: p.unit || 'pcs',
                        note: saved.note || null,
                    };
                },

                canAdd(line, by) {
                    if (!line.track_stock) return true;
                    return (line.qty + by) <= line.stock_available;
                },

                setQty(i, qty) {
                    const line = this.cart[i];
                    if (!line) return;
                    this.lastScannedIndex = i;
                    if (line.track_stock && qty > line.stock_available) {
                        this.errorMsg = this.t('only_in_stock', { qty: line.stock_available, unit: this.unitLabel(line.unit), name: line.name });
                        setTimeout(() => { this.errorMsg = ''; }, 2500);
                        qty = line.stock_available;
                    }
                    if (qty < 1) qty = 1;
                    line.qty = qty;
                    this.recompute();
                },

                incQty(i) {
                    this.lastScannedIndex = i;
                    const line = this.cart[i];
                    if (!this.canAdd(line, 1)) {
                        this.errorMsg = this.t('only_in_stock', { qty: line.stock_available, unit: this.unitLabel(line.unit), name: line.name });
                        setTimeout(() => { this.errorMsg = ''; }, 2500);
                        return;
                    }
                    line.qty++; this.recompute();
                },
                decQty(i) { this.lastScannedIndex = i; if (this.cart[i].qty > 1) { this.cart[i].qty--; this.recompute(); } else { this.removeLine(i); } },
                onQtyChange(i) {
                    this.lastScannedIndex = i;
                    const line = this.cart[i];
                    if (line.track_stock && line.qty > line.stock_available) {
                        this.errorMsg = this.t('only_in_stock', { qty: line.stock_available, unit: this.unitLabel(line.unit), name: line.name });
                        setTimeout(() => { this.errorMsg = ''; }, 2500);
                        line.qty = line.stock_available;
                    }
                    if (line.qty < 0.0001) line.qty = 1;
                    this.recompute();
                },
                removeLine(i) {
                    this.cart.splice(i, 1);
                    if (this.lastScannedIndex === i) this.lastScannedIndex = null;
                    else if (this.lastScannedIndex !== null && this.lastScannedIndex > i) this.lastScannedIndex--;
                    if (!this.cart.length) this.cartDiscount = { type: 'pct', value: 0 };
                    this.recompute();
                },
                clearCart() {
                    if (!this.cart.length || confirm(this.t('confirm_clear_cart'))) {
                        this.cart = [];
                        this.cartDiscount = { type: 'pct', value: 0 };
                        this.lastScannedIndex = null;
                        this.recompute();
                    }
                },

                // Back to a blank on-site retail sale (after checkout or hold).
                resetSale() {
                    this.cart = [];
                    this.customer = null;
                    this.lastScannedIndex = null;
                    this.priceTier = 'retail';
                    this.cartDiscount = { type: 'pct', value: 0 };
                    this.channel = 'onsite';
                    this.channelSource = '';
                },

                setChannel(ch) {
                    this.channel = ch;
                    if (ch !== 'online') this.channelSource = '';
                },

                // The source only travels with an online sale; '' means "not specified".
                channelPayload() { return this.channel === 'online' && this.channelSource ? this.channelSource : null; },

                // One pass over a cart: price, line discount, the line's share of the cart
                // discount, tax, total. Mirrors SaleService::totalsFromCart step for step
                // (every amount rounded to the 4 stored decimals per line, then summed) so
                // the screen total equals sales.total_usd. Change both or neither.
                // Pure: the discount editor runs it on a trial cart before applying a change.
                computeCart(cart, cartDiscount, customer) {
                    const r4 = (x) => Math.round(x * 1e4 + (x < 0 ? -1e-7 : 1e-7)) / 1e4;
                    const lines = cart.map((l) => {
                        const gross = r4(l.qty * l.unit_price);
                        const lineDisc = r4((l.discount_amount || 0) + gross * (l.discount_pct || 0) / 100);
                        return { gross, lineDisc, net: r4(gross - lineDisc), share: 0, discountable: l.allow_discount !== false };
                    });

                    // Cart discount: % of, or a flat amount off, the discountable net; split in
                    // proportion to each line's net, rounding remainder on the largest line.
                    const pool = r4(lines.reduce((a, c) => a + (c.discountable ? c.net : 0), 0));
                    const value = Number(cartDiscount?.value) || 0;
                    const wanted = r4(cartDiscount?.type === 'pct' ? pool * value / 100 : value);
                    const cartDisc = Math.min(wanted, pool); // an overshoot is reported by discountIssues()
                    if (cartDisc > 0) {
                        let largest = -1, allocated = 0;
                        lines.forEach((c, i) => {
                            if (!c.discountable || c.net <= 0) return;
                            c.share = r4(cartDisc * c.net / pool);
                            allocated += c.share;
                            if (largest < 0 || c.net > lines[largest].net) largest = i;
                        });
                        lines[largest].share = r4(lines[largest].share + (cartDisc - allocated));
                    }

                    const exempt = !!customer?.tax_exempt;
                    let subtotal = 0, discount = 0, tax = 0, total = 0;
                    const out = lines.map((c, i) => {
                        const l = cart[i];
                        const disc = r4(c.lineDisc + c.share);
                        const net = r4(c.net - c.share);
                        const rate = l.is_taxable && !exempt ? (l.tax_rate || 0) : 0;
                        const lineTax = rate > 0 ? r4(l.tax_inclusive ? net - net / (1 + rate) : net * rate) : 0;
                        const lineTotal = r4(l.tax_inclusive ? net : net + lineTax);
                        subtotal += c.gross; discount += disc; tax += lineTax; total += lineTotal;
                        return {
                            gross: c.gross, lineDisc: c.lineDisc, share: c.share, discount: disc,
                            net, rate, tax: lineTax, total: lineTotal,
                            effPct: c.gross > 0 ? disc / c.gross * 100 : 0,
                        };
                    });

                    return {
                        lines: out, pool, wanted, cartDisc,
                        subtotal: r4(subtotal), discount: r4(discount), tax: r4(tax), total: r4(total),
                    };
                },

                get calc() { return this.computeCart(this.cart, this.cartDiscount, this.customer); },

                get totals() { return this.calc; },

                lineCalc(i) {
                    return this.calc.lines[i] ?? { gross: 0, lineDisc: 0, share: 0, discount: 0, net: 0, tax: 0, total: 0, effPct: 0 };
                },

                // On-screen VAT row: "VAT (11%)" when every taxed line shares one rate, else just "VAT".
                get vatLabel() {
                    const rates = new Set(this.calc.lines.filter(c => c.rate > 0).map(c => c.rate));
                    return rates.size === 1
                        ? `${this.t('vat')} (${Number(([...rates][0] * 100).toFixed(2))}%)`
                        : this.t('vat');
                },

                hasInclusive() { return this.cart.some(l => l.is_taxable && l.tax_inclusive); },

                // totals/calc are getters over computeCart(), so Alpine re-renders on its own.
                recompute() { /* hook kept for the call sites; nothing to do */ },

                // --- discounts ---------------------------------------------------
                // Everything the server would refuse for this cart, as readable messages.
                discountIssues(cart, cartDiscount) {
                    const c = this.computeCart(cart, cartDiscount, this.customer);
                    const out = [];
                    cart.forEach((l, i) => {
                        const lc = c.lines[i];
                        if (lc.lineDisc > 0 && l.allow_discount === false) {
                            out.push(this.t('no_discount_product', { name: l.name }));
                        } else if (lc.lineDisc > lc.gross + 0.00005) {
                            out.push(this.t('over_line', { name: l.name }));
                        } else if (lc.effPct > this.maxDiscountPct + 0.01) {
                            out.push(this.t('over_limit', { name: l.name, pct: Number(lc.effPct.toFixed(2)), limit: this.maxDiscountPct }));
                        }
                    });
                    if (c.wanted > c.pool + 0.00005) out.push(this.t('cart_over_pool'));
                    return out;
                },

                get issues() { return this.discountIssues(this.cart, this.cartDiscount); },

                lineDiscountText(i) {
                    const l = this.cart[i], c = this.calc.lines[i];
                    if (!l || !c || c.lineDisc <= 0) return '';
                    const amt = '-$' + c.lineDisc.toFixed(2);
                    return l.discount_pct > 0 ? `-${Number(l.discount_pct)}% (${amt})` : amt;
                },

                // Soft warning, managers only (cashiers are never sent cost). Net unit price
                // after both discounts, ex-VAT, against cost.
                belowCost(i) {
                    const l = this.cart[i], c = this.calc.lines[i];
                    if (!this.showCost || !l || l.cost_usd == null || !(l.qty > 0)) return false;
                    const exVat = l.is_taxable && l.tax_inclusive ? c.net / (1 + (l.tax_rate || 0)) : c.net;
                    return exVat / l.qty < l.cost_usd - 0.00005;
                },

                selectedLine() {
                    return this.lastScannedIndex ?? (this.cart.length ? this.cart.length - 1 : null);
                },

                editorLine() { return this.editor.target === 'line' ? (this.cart[this.editor.idx] ?? null) : null; },

                editorBlocked() { return this.editor.target === 'line' && this.editorLine()?.allow_discount === false; },

                _focusEditor() {
                    this.$nextTick(() => { this.$refs.editorInput?.focus(); this.$refs.editorInput?.select(); });
                },

                openLineEditor(i) {
                    const l = this.cart[i];
                    if (i == null || !l) return;
                    this.lastScannedIndex = i;
                    const byAmount = !(l.discount_pct > 0) && l.discount_amount > 0;
                    this.editor = {
                        open: true, target: 'line', idx: i,
                        type: byAmount ? 'amount' : 'pct',
                        value: (byAmount ? l.discount_amount : l.discount_pct) || '',
                        error: '',
                    };
                    this._focusEditor();
                },

                openCartEditor() {
                    if (!this.cart.length) return;
                    this.editor = {
                        open: true, target: 'cart', idx: null,
                        type: this.cartDiscount.type, value: this.cartDiscount.value || '', error: '',
                    };
                    this._focusEditor();
                },

                closeEditor() {
                    this.editor.open = false;
                    // Hand focus back so the next barcode scan lands in the search box.
                    this.$nextTick(() => this.$refs.search?.focus());
                },

                applyEditor() {
                    const e = this.editor;
                    if (this.editorBlocked()) return;
                    const raw = String(e.value ?? '').trim();
                    const v = raw === '' ? 0 : Number(raw);
                    if (!Number.isFinite(v) || v < 0) { e.error = this.t('invalid_number'); return; }

                    // Try the change on a copy; an over-limit entry never reaches the real cart.
                    const trialCart = this.cart.map(l => ({ ...l }));
                    let trialCd = { ...this.cartDiscount };
                    if (e.target === 'line') {
                        trialCart[e.idx].discount_pct = e.type === 'pct' ? v : 0;
                        trialCart[e.idx].discount_amount = e.type === 'amount' ? v : 0;
                    } else {
                        trialCd = { type: e.type, value: v };
                    }
                    // Only block on what this change adds: an unrelated line that is already over
                    // its limit must not stop the cashier fixing it, or any other line.
                    const existing = this.discountIssues(this.cart, this.cartDiscount);
                    const problem = this.discountIssues(trialCart, trialCd).find(m => !existing.includes(m));
                    if (problem) { e.error = problem; return; }

                    if (e.target === 'line') {
                        this.cart[e.idx].discount_pct = trialCart[e.idx].discount_pct;
                        this.cart[e.idx].discount_amount = trialCart[e.idx].discount_amount;
                    } else {
                        this.cartDiscount = trialCd;
                    }
                    this.closeEditor();
                },

                removeEditorDiscount() {
                    this.editor.value = 0;
                    this.applyEditor();
                },

                clearCartDiscount() { this.cartDiscount = { type: 'pct', value: 0 }; },

                openPayment() {
                    if (!this.cart.length || this.issues.length) return;
                    this.payment = { amount_usd: 0, amount_lbp: 0, amount_card: 0, card_type: 'Visa', card_reference: '', amount_wish: 0, wish_reference: '', change_usd_out: null };
                    this.payMode = 'cash';
                    this.changePreview = null;
                    this.errorMsg = '';
                    this.showPayment = true;
                    this.$nextTick(() => this.$refs.cashUsd?.focus());
                },

                // Choosing a method pre-fills the obvious amounts and clears the ones that cannot apply together.
                // Cash keeps its own fields; Card and Wish take the whole total; Split leaves everything to the cashier.
                setPayMode(mode) {
                    const total = Number(this.totals.total.toFixed(2));
                    this.payMode = mode;
                    if (mode === 'cash') {
                        Object.assign(this.payment, { amount_card: 0, amount_wish: 0, card_reference: '', wish_reference: '' });
                        this.$nextTick(() => this.$refs.cashUsd?.focus());
                    } else if (mode === 'card') {
                        Object.assign(this.payment, { amount_usd: 0, amount_lbp: 0, amount_wish: 0, wish_reference: '', change_usd_out: null, amount_card: total });
                    } else if (mode === 'wish') {
                        Object.assign(this.payment, { amount_usd: 0, amount_lbp: 0, amount_card: 0, card_reference: '', change_usd_out: null, amount_wish: total });
                    }
                    this.refreshChangePreview();
                },

                // Money already taken: cash (USD + LBP at the till rate) + card + Wish. The one "paid" figure
                // the modal reads; the readout, the change and the Complete button all derive from it.
                tenderedUsd() {
                    return this.cashTenderedUsd() + this.nonCashUsd();
                },
                cashTenderedUsd() {
                    return (this.payment.amount_usd || 0) + (this.payment.amount_lbp || 0) / this.exchangeRate;
                },
                // Card and Wish are money that moved elsewhere: they can fill the total but never exceed it.
                nonCashUsd() {
                    return (this.payment.amount_card || 0) + (this.payment.amount_wish || 0);
                },
                noncashExceeds() { return this.nonCashUsd() > this.totals.total + 0.005; },
                // What is left for cash to cover; never negative (this is what the change preview works from).
                cashDueUsd() { return Math.max(0, this.totals.total - this.nonCashUsd()); },
                // Cash typed in when card / Wish already cover everything is a keying mistake the server refuses too.
                cashNotNeeded() { return this.cashDueUsd() <= 0.005 && this.cashTenderedUsd() > 0.005; },
                remainingUsd() { return Math.max(0, this.totals.total - this.tenderedUsd()); },
                changeUsd() { return this.tenderedUsd() - this.totals.total; },
                canComplete() {
                    return !this.processing && this.changeUsd() >= -0.005 && !this.noncashExceeds() && !this.cashNotNeeded();
                },

                // Set a card / Wish row to whatever the other rows leave uncovered (0 if they already cover it).
                fillRemaining(field) {
                    const others = this.tenderedUsd() - (this.payment[field] || 0);
                    this.payment[field] = Number(Math.max(0, this.totals.total - others).toFixed(2));
                    this.refreshChangePreview();
                },

                setSplit(mode) {
                    if (mode === 'all_usd') {
                        this.payment.change_usd_out = Number(this.changeUsd().toFixed(2));
                    } else if (mode === 'all_lbp') {
                        this.payment.change_usd_out = 0;
                    }
                    this.refreshChangePreview();
                },

                refreshChangePreview() {
                    if (this._previewTimer) clearTimeout(this._previewTimer);
                    this._previewTimer = setTimeout(() => this._fetchChangePreview(), 150);
                },

                async _fetchChangePreview() {
                    if (this.changeUsd() <= 0.005 || this.noncashExceeds()) {
                        this.changePreview = null;
                        return;
                    }
                    try {
                        const res = await fetch('/pos/api/preview-change', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            },
                            body: JSON.stringify({
                                // The cash due, never negative: card / Wish take their share first.
                                total_usd: this.cashDueUsd(),
                                paid_usd: this.payment.amount_usd || 0,
                                paid_lbp: this.payment.amount_lbp || 0,
                                change_usd_out: this.payment.change_usd_out,
                            }),
                        });
                        if (res.ok) this.changePreview = await res.json();
                    } catch (e) { /* preview is non-critical */ }
                },

                async submit() {
                    if (this.processing) return;
                    this.processing = true;
                    this.errorMsg = '';
                    try {
                        const res = await fetch('/pos/api/sales', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            },
                            body: JSON.stringify({
                                // No prices: the server prices every line from the product and price list.
                                cart: this.cart.map(l => ({
                                    product_id: l.product_id,
                                    qty: l.qty,
                                    discount_pct: l.discount_pct,
                                    discount_amount: l.discount_amount,
                                })),
                                price_tier: this.priceTier,
                                cart_discount_pct: this.cartDiscount.type === 'pct' && this.cartDiscount.value > 0
                                    ? Number(this.cartDiscount.value) : null,
                                cart_discount_amount: this.cartDiscount.type === 'amount' && this.cartDiscount.value > 0
                                    ? Number(this.cartDiscount.value) : null,
                                payment: this.payment,
                                customer_id: this.customer?.id || null,
                                channel: this.channel,
                                channel_source: this.channelPayload(),
                            }),
                        });
                        const data = await res.json();
                        if (!res.ok) {
                            this.errorMsg = this.errorText(data);
                            return;
                        }
                        this.lastSale = data.sale;
                        this.showPayment = false;
                        this.resetSale();
                        // decrement local stock so UI reflects reality without refresh
                        for (const item of data.items || []) {
                            const p = this.allProducts.find(pp => pp.id === item.product_id);
                            if (p && p.track_stock) p.stock_qty = Number(p.stock_qty) - Number(item.qty);
                        }
                        this.filter();
                        // "Ask" prints nothing until the cashier picks a format on the panel.
                        if (this.autoPrint && this.lastSale?.id && this.receiptFormat !== 'ask') {
                            this.printLast(this.defaultFormat());
                        }
                    } catch (e) {
                        this.errorMsg = this.t('network_error', { msg: e.message });
                    } finally {
                        this.processing = false;
                    }
                },

                // What Ctrl+P and auto-print use: A4 only when the setting says so ("ask" has no default).
                defaultFormat() { return this.receiptFormat === 'a4' ? 'a4' : 'thermal'; },

                printLast(format) {
                    if (!this.lastSale?.id) return;
                    if (format === 'a4') this._printA4(this.lastSale.id);
                    else this._printReceipt(this.lastSale.id);
                },

                // A4 goes straight to the printer chosen in Settings when the desktop app can do that;
                // otherwise (or if the printer refuses) the A4 page opens with the system print dialog,
                // so the user picks the A4 printer instead of the invoice landing on receipt paper.
                async _printA4(saleId) {
                    if (this.a4Silent) {
                        try {
                            const res = await fetch(`/pos/receipts/${saleId}/print-a4`, {
                                method: 'POST',
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this._csrf() },
                            });
                            if (res.ok && (await res.json()).printed) return;
                        } catch (e) { /* fall through to the dialog */ }
                    }
                    this._printReceipt(saleId, 'a4');
                },

                _printReceipt(saleId, format = 'thermal') {
                    // Open the receipt in a hidden popup. The receipt view auto-calls
                    // window.print() on load. A user-initiated event (the Pay click) is
                    // the trigger, so popups are not blocked in Electron / modern browsers.
                    const url = `/pos/receipts/${saleId}/print?format=${format}&auto=1`;
                    const w = window.open(url, 'receipt-' + saleId + '-' + format,
                        format === 'a4' ? 'width=900,height=1000' : 'width=420,height=640');
                    // Some browsers block; fall back to navigating an iframe.
                    if (!w) {
                        let iframe = document.getElementById('__receipt_iframe');
                        if (!iframe) {
                            iframe = document.createElement('iframe');
                            iframe.id = '__receipt_iframe';
                            iframe.style.position = 'fixed';
                            iframe.style.left = '-9999px';
                            iframe.style.width = '0';
                            iframe.style.height = '0';
                            iframe.style.border = '0';
                            document.body.appendChild(iframe);
                        }
                        iframe.src = url;
                    }
                },

                // Western digits in every language; only the unit is translated.
                formatLbp(n) {
                    if (!n) return '0 ' + this.t('lbp');
                    return new Intl.NumberFormat('en-US').format(Math.round(n / this.lbpStep) * this.lbpStep) + ' ' + this.t('lbp');
                },

                // Bilingual product / category names: Arabic UI prefers name_ar when there is one.
                _ar() { return document.documentElement.lang.startsWith('ar'); },
                pname(p) { return this._ar() && p.name_ar ? p.name_ar : p.name; },
                cname(c) { return this._ar() && c.name_ar ? c.name_ar : c.name; },
                unitLabel(u) { return this.units[u] ?? u; },
            }
        }
    </script>
    @endpush
</x-pos-layout>
