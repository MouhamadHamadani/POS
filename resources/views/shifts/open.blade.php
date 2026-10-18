<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Open Shift') }}</h2></x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-card sm:rounded-lg p-6">
            @if (session('warning'))
                <div class="mb-4 p-3 bg-yellow-50 text-yellow-800 rounded">{{ session('warning') }}</div>
            @endif

            <p class="text-gray-700 mb-6 text-sm">{{ __('shifts.open_intro') }}</p>

            <form method="POST" action="{{ route('shifts.open.store') }}" class="space-y-5" x-data="denomCounter({
                usd: [100, 50, 20, 10, 5, 1],
                lbp: [100000, 50000, 20000, 10000, 5000, 1000],
                initialUsd: {{ old('opening_cash_usd', 0) }},
                initialLbp: {{ old('opening_cash_lbp', 0) }},
            })">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-3">
                        <div class="flex justify-between items-baseline">
                            <h3 class="font-semibold text-sm">{{ __('shifts.usd_denoms') }}</h3>
                            <span class="text-xs text-gray-500">{{ __('shifts.total') }}: <strong dir="ltr" x-text="'$' + usdTotal().toFixed(2)"></strong></span>
                        </div>
                        <template x-for="d in usdDenoms" :key="d">
                            <div class="flex items-center gap-2 text-sm">
                                <label class="w-14 text-end text-gray-600" dir="ltr" x-text="'$' + d"></label>
                                <span class="text-xs text-gray-400">×</span>
                                <input type="number" min="0" step="1" :name="`denominations[usd][${d}]`" dir="ltr"
                                       x-model.number="usd[d]"
                                       class="w-20 text-end border-gray-300 rounded text-sm" />
                                <span class="text-xs text-gray-500" dir="ltr" x-text="'= $' + ((usd[d] || 0) * d).toFixed(0)"></span>
                            </div>
                        </template>
                    </div>

                    <div class="space-y-3">
                        <div class="flex justify-between items-baseline">
                            <h3 class="font-semibold text-sm">{{ __('shifts.lbp_denoms') }}</h3>
                            <span class="text-xs text-gray-500">{{ __('shifts.total') }}: <strong dir="ltr" x-text="lbpTotal().toLocaleString('en-US') + ' ' + @js(__('shifts.lbp'))"></strong></span>
                        </div>
                        <template x-for="d in lbpDenoms" :key="d">
                            <div class="flex items-center gap-2 text-sm">
                                <label class="w-20 text-end text-gray-600 text-xs" dir="ltr" x-text="d.toLocaleString('en-US')"></label>
                                <span class="text-xs text-gray-400">×</span>
                                <input type="number" min="0" step="1" :name="`denominations[lbp][${d}]`" dir="ltr"
                                       x-model.number="lbp[d]"
                                       class="w-20 text-end border-gray-300 rounded text-sm" />
                                <span class="text-[11px] text-gray-500" dir="ltr" x-text="'= ' + ((lbp[d] || 0) * d).toLocaleString('en-US')"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="border-t pt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="opening_cash_usd" :value="__('shifts.opening_cash_usd')" />
                        <x-text-input id="opening_cash_usd" name="opening_cash_usd" type="number" step="0.01" min="0" dir="ltr"
                                      :value="old('opening_cash_usd', 0)" required class="block mt-1 w-full"
                                      x-bind:value="usdTotal() || {{ old('opening_cash_usd', 0) }}"
                                      x-bind:readonly="hasUsdDenom()" />
                        <p class="text-xs text-gray-500 mt-1" x-show="hasUsdDenom()">{{ __('shifts.auto_calc') }}</p>
                        <x-input-error :messages="$errors->get('opening_cash_usd')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="opening_cash_lbp" :value="__('shifts.opening_cash_lbp')" />
                        <x-text-input id="opening_cash_lbp" name="opening_cash_lbp" type="number" step="any" min="0" dir="ltr"
                                      :value="old('opening_cash_lbp', 0)" required class="block mt-1 w-full"
                                      x-bind:value="lbpTotal() || {{ old('opening_cash_lbp', 0) }}"
                                      x-bind:readonly="hasLbpDenom()" />
                        <x-input-error :messages="$errors->get('opening_cash_lbp')" class="mt-2" />
                    </div>
                </div>

                <div>
                    <x-input-label for="notes" :value="__('shifts.notes_optional')" />
                    <textarea id="notes" name="notes" dir="auto" rows="2" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">{{ old('notes') }}</textarea>
                </div>

                <div class="flex items-center justify-end">
                    <x-primary-button>{{ __('shifts.open_and_go') }}</x-primary-button>
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
                // Each total field locks only on its own currency's count; the server also treats currencies independently.
                hasUsdDenom() { return this.usdTotal() > 0; },
                hasLbpDenom() { return this.lbpTotal() > 0; },
            }
        }
    </script>
    @endpush
</x-app-layout>
