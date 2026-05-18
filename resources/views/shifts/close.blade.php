<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Close Shift') }}</h2></x-slot>

    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-card sm:rounded-lg p-6">
            <h3 class="text-lg font-medium mb-4">Shift summary</h3>
            <dl class="grid grid-cols-2 gap-4 mb-6 text-sm">
                <div><dt class="text-gray-500">Opened</dt><dd class="font-medium">{{ $shift->opened_at }}</dd></div>
                <div><dt class="text-gray-500">Sales count</dt><dd class="font-medium">{{ $totals['sales_count'] }}</dd></div>
                <div><dt class="text-gray-500">Revenue (USD)</dt><dd class="font-medium">${{ number_format($totals['total_revenue_usd'], 2) }}</dd></div>
                <div><dt class="text-gray-500">Opening (USD)</dt><dd class="font-medium">${{ number_format((float) $shift->opening_cash_usd, 2) }}</dd></div>
                <div><dt class="text-gray-500">Expected cash (USD)</dt><dd class="font-medium">${{ number_format((float) $shift->opening_cash_usd + $totals['cash_in_usd'], 2) }}</dd></div>
                <div><dt class="text-gray-500">Expected cash (LBP)</dt><dd class="font-medium">{{ number_format((float) $shift->opening_cash_lbp + $totals['cash_in_lbp']) }}</dd></div>
            </dl>

            <form method="POST" action="{{ route('shifts.close.store') }}" class="space-y-5" x-data="denomCounter({
                usd: [100, 50, 20, 10, 5, 1],
                lbp: [100000, 50000, 20000, 10000, 5000, 1000],
            })">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-3">
                        <div class="flex justify-between items-baseline">
                            <h3 class="font-semibold text-sm">Count USD in drawer</h3>
                            <span class="text-xs text-gray-500">Total: <strong x-text="'$' + usdTotal().toFixed(2)"></strong></span>
                        </div>
                        <template x-for="d in usdDenoms" :key="d">
                            <div class="flex items-center gap-2 text-sm">
                                <label class="w-14 text-right text-gray-600" x-text="'$' + d"></label>
                                <span class="text-xs text-gray-400">×</span>
                                <input type="number" min="0" step="1" :name="`denominations[usd][${d}]`"
                                       x-model.number="usd[d]"
                                       class="w-20 text-right border-gray-300 rounded text-sm" />
                                <span class="text-xs text-gray-500" x-text="'= $' + ((usd[d] || 0) * d).toFixed(0)"></span>
                            </div>
                        </template>
                    </div>

                    <div class="space-y-3">
                        <div class="flex justify-between items-baseline">
                            <h3 class="font-semibold text-sm">Count LBP in drawer</h3>
                            <span class="text-xs text-gray-500">Total: <strong x-text="lbpTotal().toLocaleString() + ' LBP'"></strong></span>
                        </div>
                        <template x-for="d in lbpDenoms" :key="d">
                            <div class="flex items-center gap-2 text-sm">
                                <label class="w-20 text-right text-gray-600 text-xs" x-text="d.toLocaleString()"></label>
                                <span class="text-xs text-gray-400">×</span>
                                <input type="number" min="0" step="1" :name="`denominations[lbp][${d}]`"
                                       x-model.number="lbp[d]"
                                       class="w-20 text-right border-gray-300 rounded text-sm" />
                                <span class="text-[11px] text-gray-500" x-text="'= ' + ((lbp[d] || 0) * d).toLocaleString()"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="border-t pt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="closing_cash_usd" value="Counted Cash (USD)" />
                        <x-text-input id="closing_cash_usd" name="closing_cash_usd" type="number" step="0.01" min="0"
                                      :value="old('closing_cash_usd', 0)" required class="block mt-1 w-full"
                                      x-bind:value="usdTotal() || {{ old('closing_cash_usd', 0) }}"
                                      x-bind:readonly="hasAnyDenom()" />
                        <p class="text-xs text-gray-500 mt-1" x-show="hasAnyDenom()">Auto-calculated from denominations above.</p>
                    </div>
                    <div>
                        <x-input-label for="closing_cash_lbp" value="Counted Cash (LBP)" />
                        <x-text-input id="closing_cash_lbp" name="closing_cash_lbp" type="number" step="1000" min="0"
                                      :value="old('closing_cash_lbp', 0)" required class="block mt-1 w-full"
                                      x-bind:value="lbpTotal() || {{ old('closing_cash_lbp', 0) }}"
                                      x-bind:readonly="hasAnyDenom()" />
                    </div>
                </div>

                <div>
                    <x-input-label for="notes" value="Notes (optional)" />
                    <textarea id="notes" name="notes" rows="2" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">{{ old('notes') }}</textarea>
                </div>

                <div class="flex items-center justify-end">
                    <x-primary-button class="!bg-red-600 hover:!bg-red-700">Close Shift</x-primary-button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <script>
        function denomCounter(cfg) {
            return {
                usdDenoms: cfg.usd,
                lbpDenoms: cfg.lbp,
                usd: Object.fromEntries(cfg.usd.map(d => [d, 0])),
                lbp: Object.fromEntries(cfg.lbp.map(d => [d, 0])),
                usdTotal() { return Object.entries(this.usd).reduce((s, [d, c]) => s + (Number(c) || 0) * Number(d), 0); },
                lbpTotal() { return Object.entries(this.lbp).reduce((s, [d, c]) => s + (Number(c) || 0) * Number(d), 0); },
                hasAnyDenom() { return this.usdTotal() > 0 || this.lbpTotal() > 0; },
            }
        }
    </script>
    @endpush
</x-app-layout>
