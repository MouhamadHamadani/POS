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
                        <div class="flex gap-2 mb-3">
                            <input type="text" x-model="search" @input.debounce.250ms="filter()"
                                   placeholder="Search by name, SKU, or scan barcode + Enter"
                                   @keydown.enter="onBarcodeEnter($event)"
                                   class="flex-1 border-gray-300 rounded-md text-sm" autofocus />
                            <button type="button" @click="activeCategory=null;filter()"
                                    class="px-3 py-1 text-xs bg-gray-100 rounded">All</button>
                        </div>

                        {{-- Category tabs --}}
                        <div class="flex flex-wrap gap-2 mb-3">
                            <template x-for="cat in categories" :key="cat.id">
                                <button type="button" @click="activeCategory = cat.id; filter()"
                                        :class="activeCategory === cat.id ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700'"
                                        class="px-3 py-1 text-xs rounded">
                                    <span x-text="cat.name"></span>
                                </button>
                            </template>
                        </div>

                        {{-- Product grid --}}
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 max-h-[60vh] overflow-y-auto">
                            <template x-for="p in filtered" :key="p.id">
                                <button type="button" @click="addToCart(p)"
                                        :disabled="p.track_stock && p.stock_qty <= 0"
                                        class="p-2 bg-white border rounded-lg hover:bg-blue-50 hover:border-blue-300 text-left disabled:opacity-40 disabled:cursor-not-allowed">
                                    <div class="text-xs font-medium text-gray-900 line-clamp-2" x-text="p.name"></div>
                                    <div class="text-[11px] text-gray-500 mt-0.5" x-text="p.sku || p.barcode || ''"></div>
                                    <div class="mt-1 text-sm font-bold text-blue-700">$<span x-text="Number(p.price_usd).toFixed(2)"></span></div>
                                    <div class="text-[11px] text-gray-500" x-text="`Stock: ${Number(p.stock_qty).toFixed(0)} ${p.unit}`"></div>
                                </button>
                            </template>
                            <div x-show="filtered.length === 0" class="col-span-full text-center text-gray-500 py-6 text-sm">
                                No products match.
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Right: cart + totals --}}
                <div class="lg:col-span-5">
                    <div class="bg-white rounded-lg shadow-sm">
                        <div class="p-3 border-b flex justify-between items-center">
                            <h3 class="font-medium">Cart (<span x-text="cart.length"></span>)</h3>
                            <button type="button" @click="clearCart()" x-show="cart.length"
                                    class="text-xs text-red-600 hover:underline">Clear</button>
                        </div>

                        <div class="divide-y max-h-[40vh] overflow-y-auto">
                            <template x-for="(line, idx) in cart" :key="line.product_id">
                                <div class="p-2 flex items-center gap-2 text-sm">
                                    <div class="flex-1">
                                        <div class="font-medium" x-text="line.name"></div>
                                        <div class="text-xs text-gray-500">
                                            $<span x-text="Number(line.unit_price).toFixed(2)"></span> × <span x-text="line.qty"></span>
                                        </div>
                                    </div>
                                    <div class="flex gap-1 items-center">
                                        <button type="button" @click="decQty(idx)" class="w-6 h-6 bg-gray-100 rounded">-</button>
                                        <input type="number" x-model.number="line.qty" @change="onQtyChange(idx)" min="0.0001" step="1"
                                               class="w-12 text-center border-gray-300 rounded text-sm py-0.5" />
                                        <button type="button" @click="incQty(idx)" class="w-6 h-6 bg-gray-100 rounded">+</button>
                                    </div>
                                    <div class="w-16 text-right font-medium">$<span x-text="lineTotal(line).toFixed(2)"></span></div>
                                    <button type="button" @click="removeLine(idx)" class="text-red-500 text-xs">×</button>
                                </div>
                            </template>
                            <div x-show="cart.length === 0" class="p-6 text-center text-sm text-gray-500">
                                Cart is empty. Tap a product to start.
                            </div>
                        </div>

                        <div class="p-3 border-t space-y-1 text-sm">
                            <div class="flex justify-between"><span class="text-gray-500">Subtotal</span><span>$<span x-text="totals.subtotal.toFixed(2)"></span></span></div>
                            <div class="flex justify-between text-gray-500"><span>Discount</span><span>-$<span x-text="totals.discount.toFixed(2)"></span></span></div>
                            <div class="flex justify-between text-gray-500"><span>VAT (11%)</span><span>$<span x-text="totals.tax.toFixed(2)"></span></span></div>
                            <div class="flex justify-between text-lg font-bold border-t pt-2 mt-2">
                                <span>Total</span>
                                <span class="text-blue-700">$<span x-text="totals.total.toFixed(2)"></span></span>
                            </div>
                            <div class="flex justify-between text-xs text-gray-500">
                                <span>LBP equiv.</span>
                                <span x-text="formatLbp(totals.total * exchangeRate)"></span>
                            </div>

                            <button type="button" @click="openPayment()" :disabled="cart.length === 0"
                                    class="w-full mt-3 py-2 bg-green-600 text-white rounded hover:bg-green-700 disabled:bg-gray-300 disabled:cursor-not-allowed font-medium">
                                Pay (F4)
                            </button>

                            <div class="flex gap-2 mt-2">
                                <button type="button" @click="openHold()" :disabled="cart.length === 0"
                                        class="flex-1 py-2 bg-amber-500 text-white rounded text-sm hover:bg-amber-600 disabled:bg-gray-300 disabled:cursor-not-allowed">
                                    Hold (F5)
                                </button>
                                <button type="button" @click="openHeldList()"
                                        class="flex-1 py-2 bg-slate-700 text-white rounded text-sm hover:bg-slate-800">
                                    Recall <span x-show="heldCount > 0" class="text-xs bg-amber-400 text-slate-900 rounded px-1 ml-1" x-text="heldCount"></span> (F6)
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Hold modal: optional label/notes before sending the cart to the held-sales bin --}}
            <div x-show="showHoldModal" x-cloak @keydown.escape.window="showHoldModal=false"
                 class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-5">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-lg font-bold">Hold this sale</h3>
                        <button type="button" @click="showHoldModal=false" class="text-gray-400 hover:text-gray-700 text-xl">×</button>
                    </div>
                    <p class="text-sm text-gray-600 mb-3">Give the cart a label so you can recall it. No stock is reserved.</p>
                    <div class="space-y-3 text-sm">
                        <div>
                            <label class="block text-xs font-medium text-gray-600">Label (optional)</label>
                            <input type="text" x-model="holdDraft.label" maxlength="80"
                                   placeholder="e.g. Table 5, Mr. Khoury"
                                   class="w-full border-gray-300 rounded mt-1 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600">Notes (optional)</label>
                            <textarea x-model="holdDraft.notes" rows="2" maxlength="500"
                                      class="w-full border-gray-300 rounded mt-1 text-sm"></textarea>
                        </div>
                        <div x-show="errorMsg" x-cloak class="text-sm text-red-600 bg-red-50 p-2 rounded" x-text="errorMsg"></div>
                        <button type="button" @click="submitHold()" :disabled="holdProcessing"
                                class="w-full py-3 bg-amber-500 text-white rounded font-bold hover:bg-amber-600 disabled:bg-gray-300 disabled:cursor-not-allowed">
                            <span x-show="!holdProcessing">Hold Sale</span>
                            <span x-show="holdProcessing">Holding…</span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Held sales list (recall) --}}
            <div x-show="showHeldList" x-cloak @keydown.escape.window="showHeldList=false"
                 class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full p-5 max-h-[80vh] overflow-y-auto">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-lg font-bold">Held sales</h3>
                        <button type="button" @click="showHeldList=false" class="text-gray-400 hover:text-gray-700 text-xl">×</button>
                    </div>
                    <div x-show="heldList.length === 0" class="py-8 text-center text-sm text-gray-500">No held sales right now.</div>
                    <div class="space-y-2">
                        <template x-for="h in heldList" :key="h.id">
                            <div class="border rounded p-3 flex items-center gap-3 text-sm">
                                <div class="flex-1">
                                    <div class="font-medium">
                                        <span x-text="h.label || ('Hold #' + h.id)"></span>
                                        <span x-show="h.customer" class="text-xs text-gray-500" x-text="'· ' + (h.customer?.name)"></span>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        <span x-text="h.item_count + ' items'"></span>
                                        · $<span x-text="Number(h.subtotal).toFixed(2)"></span>
                                        · <span x-text="new Date(h.held_at).toLocaleTimeString()"></span>
                                    </div>
                                    <div x-show="h.notes" class="text-xs italic text-gray-500" x-text="h.notes"></div>
                                </div>
                                <button type="button" @click="recallHold(h.id)"
                                        class="px-3 py-1.5 bg-green-600 text-white rounded text-xs hover:bg-green-700">Recall</button>
                                <button type="button" @click="discardHold(h.id)"
                                        class="px-3 py-1.5 bg-red-600 text-white rounded text-xs hover:bg-red-700">Discard</button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Payment modal --}}
            <div x-show="showPayment" x-cloak @keydown.escape.window="showPayment=false"
                 class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-5">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-lg font-bold">Payment</h3>
                        <button type="button" @click="showPayment=false" class="text-gray-400 hover:text-gray-700 text-xl">×</button>
                    </div>

                    <div class="text-center py-3 bg-blue-50 rounded mb-4">
                        <div class="text-sm text-gray-600">Total Due</div>
                        <div class="text-3xl font-bold text-blue-700">$<span x-text="totals.total.toFixed(2)"></span></div>
                        <div class="text-xs text-gray-500" x-text="formatLbp(totals.total * exchangeRate)"></div>
                    </div>

                    <div class="space-y-3 text-sm">
                        <div>
                            <label class="block text-xs font-medium text-gray-600">Method</label>
                            <select x-model="payment.method" class="w-full border-gray-300 rounded mt-1 text-sm">
                                <option value="cash_usd">Cash (USD)</option>
                                <option value="cash_lbp">Cash (LBP)</option>
                                <option value="mixed">Mixed Cash USD + LBP</option>
                                <option value="card">Card</option>
                            </select>
                        </div>

                        <div x-show="['cash_usd','mixed'].includes(payment.method)">
                            <label class="block text-xs font-medium text-gray-600">Cash USD tendered</label>
                            <input type="number" step="0.01" min="0" x-model.number="payment.amount_usd" @input="refreshChangePreview()"
                                   class="w-full border-gray-300 rounded mt-1 text-sm" />
                            <div class="flex gap-1 mt-1">
                                <template x-for="d in [1,5,10,20,50,100]">
                                    <button type="button" @click="payment.amount_usd = (payment.amount_usd||0) + d; refreshChangePreview()"
                                            class="text-xs px-2 py-1 bg-gray-100 rounded">+$<span x-text="d"></span></button>
                                </template>
                            </div>
                        </div>

                        <div x-show="['cash_lbp','mixed'].includes(payment.method)">
                            <label class="block text-xs font-medium text-gray-600">Cash LBP tendered</label>
                            <input type="number" step="1000" min="0" x-model.number="payment.amount_lbp" @input="refreshChangePreview()"
                                   class="w-full border-gray-300 rounded mt-1 text-sm" />
                            <div class="flex gap-1 mt-1 flex-wrap">
                                <template x-for="d in [50000, 100000, 250000, 500000, 1000000]">
                                    <button type="button" @click="payment.amount_lbp = (payment.amount_lbp||0) + d; refreshChangePreview()"
                                            class="text-xs px-2 py-1 bg-gray-100 rounded" x-text="`+${(d/1000).toFixed(0)}k`"></button>
                                </template>
                            </div>
                        </div>

                        <div x-show="payment.method === 'card'">
                            <label class="block text-xs font-medium text-gray-600">Card amount (USD)</label>
                            <input type="number" step="0.01" min="0" x-model.number="payment.amount_card"
                                   class="w-full border-gray-300 rounded mt-1 text-sm" />
                            <label class="block text-xs font-medium text-gray-600 mt-2">Card type / Ref</label>
                            <div class="flex gap-2">
                                <select x-model="payment.card_type" class="border-gray-300 rounded text-sm">
                                    <option value="Visa">Visa</option><option value="MasterCard">MasterCard</option>
                                    <option value="Amex">Amex</option><option value="Other">Other</option>
                                </select>
                                <input type="text" x-model="payment.card_reference" placeholder="Approval ref"
                                       class="flex-1 border-gray-300 rounded text-sm" />
                            </div>
                        </div>

                        <div class="bg-gray-50 p-2 rounded text-xs space-y-1">
                            <div class="flex justify-between"><span>Tendered (USD eq.)</span><span>$<span x-text="tenderedUsd().toFixed(2)"></span></span></div>
                            <div class="flex justify-between font-semibold" :class="changeUsd() >= 0 ? 'text-green-700' : 'text-red-600'">
                                <span x-text="changeUsd() >= 0 ? 'Total change owed' : 'Short'"></span>
                                <span>$<span x-text="Math.abs(changeUsd()).toFixed(2)"></span></span>
                            </div>
                        </div>

                        {{-- Split-change section: cashier decides how much USD to give back --}}
                        <div x-show="changeUsd() > 0.005" class="border border-blue-200 bg-blue-50 p-2 rounded text-xs space-y-2">
                            <div class="flex justify-between items-center">
                                <span class="font-semibold text-blue-800">Change to return</span>
                                <div class="flex gap-1">
                                    <button type="button" @click="setSplit('all_usd')" class="px-2 py-0.5 bg-white border rounded text-[10px]">All USD</button>
                                    <button type="button" @click="setSplit('all_lbp')" class="px-2 py-0.5 bg-white border rounded text-[10px]">All LBP</button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-[11px] text-gray-600">Give USD</label>
                                <input type="number" step="0.01" min="0" :max="changeUsd().toFixed(2)"
                                       x-model.number="payment.change_usd_out" @input="refreshChangePreview()"
                                       class="w-full border-gray-300 rounded text-sm" />
                            </div>
                            <template x-if="changePreview">
                                <div class="space-y-1">
                                    <div class="flex justify-between">
                                        <span>USD portion</span>
                                        <span class="font-medium">$<span x-text="Number(changePreview.change.change_usd).toFixed(2)"></span></span>
                                    </div>
                                    <template x-if="changePreview.usd_denoms.length">
                                        <div class="text-[10px] text-gray-500 pl-3" x-text="changePreview.usd_denoms.map(d => d.count + ' × ' + d.label).join(' · ')"></div>
                                    </template>
                                    <div class="flex justify-between">
                                        <span>LBP balance</span>
                                        <span class="font-medium" x-text="formatLbp(Number(changePreview.change.change_lbp))"></span>
                                    </div>
                                    <template x-if="changePreview.lbp_denoms.length">
                                        <div class="text-[10px] text-gray-500 pl-3" x-text="changePreview.lbp_denoms.map(d => d.count + ' × ' + d.label).join(' · ')"></div>
                                    </template>
                                </div>
                            </template>
                        </div>

                        <div x-show="errorMsg" x-cloak class="text-sm text-red-600 bg-red-50 p-2 rounded" x-text="errorMsg"></div>

                        <button type="button" @click="submit()" :disabled="processing || changeUsd() < -0.005"
                                class="w-full py-3 bg-green-600 text-white rounded font-bold hover:bg-green-700 disabled:bg-gray-300 disabled:cursor-not-allowed">
                            <span x-show="!processing">Complete Sale (F12)</span>
                            <span x-show="processing">Processing…</span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Receipt confirmation --}}
            <div x-show="lastSale" x-cloak class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-sm w-full p-5 text-center">
                    <div class="text-green-600 text-5xl mb-2">✓</div>
                    <h3 class="text-xl font-bold mb-1">Sale Completed</h3>
                    <div class="text-sm text-gray-500 mb-3" x-text="lastSale?.receipt_number"></div>
                    <div class="text-3xl font-bold text-blue-700 mb-1">$<span x-text="Number(lastSale?.total_usd || 0).toFixed(2)"></span></div>
                    <div class="text-xs text-gray-500 mb-1" x-text="formatLbp((lastSale?.total_usd || 0) * exchangeRate)"></div>
                    <template x-if="lastSale && Number(lastSale.change_usd) > 0">
                        <div class="text-sm text-gray-700 mt-2">Change: $<span x-text="Number(lastSale.change_usd).toFixed(2)"></span></div>
                    </template>
                    <div class="flex gap-2 mt-4">
                        <button type="button" @click="reprintLast()"
                                class="flex-1 py-2 bg-slate-600 text-white rounded text-sm hover:bg-slate-700">Reprint</button>
                        <button type="button" @click="lastSale=null"
                                class="flex-1 py-2 bg-blue-600 text-white rounded">New Sale</button>
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
                search: '',
                activeCategory: null,
                filtered: [],
                cart: [],
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
                payment: { method: 'cash_usd', amount_usd: 0, amount_lbp: 0, amount_card: 0, card_type: 'Visa', card_reference: '', change_usd_out: null },
                changePreview: null,
                _previewTimer: null,

                init() {
                    this.filter();
                    this.refreshHeldCount();
                    window.addEventListener('keydown', (e) => {
                        if (e.key === 'F4') { e.preventDefault(); this.openPayment(); }
                        if (e.key === 'F5') { e.preventDefault(); this.openHold(); }
                        if (e.key === 'F6') { e.preventDefault(); this.openHeldList(); }
                        if (e.key === 'F9') { e.preventDefault(); this.clearCart(); }
                        if (e.key === 'F12' && this.showPayment) { e.preventDefault(); this.submit(); }
                        if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 'P') && this.lastSale?.id) {
                            e.preventDefault(); this._printReceipt(this.lastSale.id);
                        }
                    });
                },

                _csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; },

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
                                    unit_price: l.unit_price,
                                    discount_pct: l.discount_pct,
                                    discount_amount: l.discount_amount,
                                    note: l.note,
                                })),
                                customer_id: this.customer?.id || null,
                                label: this.holdDraft.label || null,
                                notes: this.holdDraft.notes || null,
                            }),
                        });
                        const data = await res.json();
                        if (!res.ok) {
                            this.errorMsg = typeof data.error === 'string' ? data.error : 'Could not hold the sale.';
                            return;
                        }
                        this.cart = [];
                        this.customer = null;
                        this.showHoldModal = false;
                        await this.refreshHeldCount();
                    } catch (e) {
                        this.errorMsg = 'Network error: ' + e.message;
                    } finally {
                        this.holdProcessing = false;
                    }
                },

                async openHeldList() {
                    await this.refreshHeldCount();
                    this.showHeldList = true;
                },

                async recallHold(id) {
                    if (this.cart.length > 0 && !confirm('Current cart will be cleared. Continue?')) return;
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
                            this.errorMsg = data.error || data.message || 'Could not recall.';
                            return;
                        }
                        // Reconstitute cart from the held payload using current product data.
                        const cart = [];
                        for (const line of data.cart || []) {
                            const p = this.allProducts.find(pp => pp.id === line.product_id);
                            if (!p) continue;
                            const taxRate = p.tax ? Number(p.tax.rate) : 0;
                            cart.push({
                                product_id: p.id,
                                name: p.name,
                                unit_price: Number(line.unit_price ?? p.price_usd),
                                qty: Number(line.qty),
                                discount_pct: Number(line.discount_pct ?? 0),
                                discount_amount: Number(line.discount_amount ?? 0),
                                tax_rate: taxRate,
                                is_taxable: !!p.is_taxable,
                                tax_inclusive: p.tax ? !!p.tax.is_inclusive : false,
                                track_stock: !!p.track_stock,
                                stock_available: Number(p.stock_qty),
                                unit: p.unit || 'pcs',
                                note: line.note || null,
                            });
                        }
                        this.cart = cart;
                        this.showHeldList = false;
                        await this.refreshHeldCount();
                    } catch (e) {
                        this.errorMsg = 'Network error: ' + e.message;
                    }
                },

                async discardHold(id) {
                    if (!confirm('Discard this held sale?')) return;
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
                    const hit = this.allProducts.find(p => p.barcode === raw);
                    if (hit) { this.addToCart(hit); this.search = ''; this.filter(); return; }
                    fetch(`/pos/api/barcode?code=${encodeURIComponent(raw)}`, { headers: { Accept: 'application/json' }})
                        .then(r => r.ok ? r.json() : Promise.reject())
                        .then(p => { this.addToCart(p); this.search = ''; this.filter(); })
                        .catch(() => {
                            this.errorMsg = `Barcode "${raw}" not found.`;
                            setTimeout(() => { this.errorMsg = ''; }, 3000);
                            this.search = '';
                        });
                },

                addToCart(p) {
                    if (p.track_stock && p.stock_qty <= 0) {
                        this.errorMsg = `"${p.name}" is out of stock.`;
                        setTimeout(() => { this.errorMsg = ''; }, 2500);
                        return;
                    }
                    const existing = this.cart.find(l => l.product_id === p.id);
                    if (existing) {
                        if (!this.canAdd(existing, 1)) {
                            this.errorMsg = `Only ${p.stock_qty} ${p.unit} of "${p.name}" in stock.`;
                            setTimeout(() => { this.errorMsg = ''; }, 2500);
                            return;
                        }
                        existing.qty += 1;
                    } else {
                        const taxRate = p.tax ? Number(p.tax.rate) : 0;
                        this.cart.push({
                            product_id: p.id, name: p.name,
                            unit_price: Number(p.price_usd),
                            qty: 1, discount_pct: 0, discount_amount: 0,
                            tax_rate: taxRate, is_taxable: !!p.is_taxable,
                            tax_inclusive: p.tax ? !!p.tax.is_inclusive : false,
                            track_stock: !!p.track_stock,
                            stock_available: Number(p.stock_qty),
                            unit: p.unit || 'pcs',
                        });
                    }
                    this.recompute();
                },

                canAdd(line, by) {
                    if (!line.track_stock) return true;
                    return (line.qty + by) <= line.stock_available;
                },

                incQty(i) {
                    const line = this.cart[i];
                    if (!this.canAdd(line, 1)) {
                        this.errorMsg = `Only ${line.stock_available} ${line.unit} of "${line.name}" in stock.`;
                        setTimeout(() => { this.errorMsg = ''; }, 2500);
                        return;
                    }
                    line.qty++; this.recompute();
                },
                decQty(i) { if (this.cart[i].qty > 1) { this.cart[i].qty--; this.recompute(); } else { this.removeLine(i); } },
                onQtyChange(i) {
                    const line = this.cart[i];
                    if (line.track_stock && line.qty > line.stock_available) {
                        this.errorMsg = `Only ${line.stock_available} ${line.unit} of "${line.name}" in stock.`;
                        setTimeout(() => { this.errorMsg = ''; }, 2500);
                        line.qty = line.stock_available;
                    }
                    if (line.qty < 0.0001) line.qty = 1;
                    this.recompute();
                },
                removeLine(i) { this.cart.splice(i, 1); this.recompute(); },
                clearCart() { if (!this.cart.length || confirm('Clear cart?')) { this.cart = []; this.recompute(); } },

                lineTotal(line) {
                    const gross = line.qty * line.unit_price;
                    const disc = (line.discount_amount || 0) + gross * (line.discount_pct || 0) / 100;
                    const net = gross - disc;
                    if (!line.is_taxable) return net;
                    return line.tax_inclusive ? net : net * (1 + (line.tax_rate || 0));
                },

                get totals() {
                    let subtotal = 0, discount = 0, tax = 0;
                    for (const line of this.cart) {
                        const gross = line.qty * line.unit_price;
                        const disc = (line.discount_amount || 0) + gross * (line.discount_pct || 0) / 100;
                        const net = gross - disc;
                        subtotal += gross; discount += disc;
                        if (line.is_taxable) {
                            tax += line.tax_inclusive ? net - net/(1+(line.tax_rate||0)) : net * (line.tax_rate || 0);
                        }
                    }
                    return { subtotal, discount, tax, total: subtotal - discount + (this.hasInclusive() ? 0 : tax) };
                },

                hasInclusive() { return this.cart.some(l => l.is_taxable && l.tax_inclusive); },

                recompute() { /* totals is a getter; this is a hook for $watch if needed */ },

                openPayment() {
                    if (!this.cart.length) return;
                    this.payment = { method: 'cash_usd', amount_usd: 0, amount_lbp: 0, amount_card: 0, card_type: 'Visa', card_reference: '', change_usd_out: null };
                    this.changePreview = null;
                    this.errorMsg = '';
                    this.showPayment = true;
                },

                tenderedUsd() {
                    return (this.payment.amount_usd || 0)
                        + (this.payment.amount_lbp || 0) / this.exchangeRate
                        + (this.payment.amount_card || 0);
                },

                changeUsd() { return this.tenderedUsd() - this.totals.total; },

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
                    if (this.changeUsd() <= 0.005) {
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
                                total_usd: this.totals.total,
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
                                cart: this.cart.map(l => ({
                                    product_id: l.product_id,
                                    qty: l.qty,
                                    unit_price: l.unit_price,
                                    discount_pct: l.discount_pct,
                                    discount_amount: l.discount_amount,
                                })),
                                payment: this.payment,
                            }),
                        });
                        const data = await res.json();
                        if (!res.ok) {
                            this.errorMsg = typeof data.error === 'string' ? data.error : JSON.stringify(data.error);
                            return;
                        }
                        this.lastSale = data.sale;
                        this.showPayment = false;
                        this.cart = [];
                        this.customer = null;
                        // decrement local stock so UI reflects reality without refresh
                        for (const item of data.items || []) {
                            const p = this.allProducts.find(pp => pp.id === item.product_id);
                            if (p && p.track_stock) p.stock_qty = Number(p.stock_qty) - Number(item.qty);
                        }
                        this.filter();
                        if (this.autoPrint && this.lastSale?.id) {
                            this._printReceipt(this.lastSale.id);
                        }
                    } catch (e) {
                        this.errorMsg = 'Network error: ' + e.message;
                    } finally {
                        this.processing = false;
                    }
                },

                _printReceipt(saleId) {
                    // Open the receipt in a hidden popup. The receipt view auto-calls
                    // window.print() on load. A user-initiated event (the Pay click) is
                    // the trigger, so popups are not blocked in Electron / modern browsers.
                    const w = window.open(`/pos/receipts/${saleId}/print?auto=1`,
                        'receipt-' + saleId, 'width=420,height=640');
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
                        iframe.src = `/pos/receipts/${saleId}/print?auto=1`;
                    }
                },

                reprintLast() {
                    if (this.lastSale?.id) this._printReceipt(this.lastSale.id);
                },

                formatLbp(n) {
                    if (!n) return '0 LBP';
                    return new Intl.NumberFormat().format(Math.round(n / this.lbpStep) * this.lbpStep) + ' LBP';
                },
            }
        }
    </script>
    @endpush
</x-pos-layout>
