<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Close Shift') }}</h2></x-slot>

    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-card sm:rounded-lg p-6">
            <h3 class="text-lg font-medium mb-4">{{ __('shifts.summary') }}</h3>
            <dl class="grid grid-cols-2 gap-4 mb-6 text-sm">
                <div><dt class="text-gray-500">{{ __('shifts.opened') }}</dt><dd class="font-medium"><x-ltr>{{ $shift->opened_at }}</x-ltr></dd></div>
                <div><dt class="text-gray-500">{{ __('shifts.sales_count') }}</dt><dd class="font-medium"><x-ltr>{{ $totals['sales_count'] }}</x-ltr></dd></div>
                <div><dt class="text-gray-500">{{ __('shifts.revenue_usd') }}</dt><dd class="font-medium"><x-ltr>${{ number_format($totals['total_revenue_usd'], 2) }}</x-ltr></dd></div>
                <div><dt class="text-gray-500">{{ __('shifts.opening_usd') }}</dt><dd class="font-medium"><x-ltr>${{ number_format((float) $shift->opening_cash_usd, 2) }}</x-ltr></dd></div>
                <div><dt class="text-gray-500">{{ __('shifts.expected_usd') }}</dt><dd class="font-medium"><x-ltr>${{ number_format($totals['expected_usd'], 2) }}</x-ltr></dd></div>
                <div><dt class="text-gray-500">{{ __('shifts.expected_lbp') }}</dt><dd class="font-medium"><x-ltr>{{ number_format($totals['expected_lbp']) }}</x-ltr></dd></div>
                @if ($totals['cash_out_usd'] > 0 || $totals['cash_out_lbp'] > 0)
                    <div><dt class="text-gray-500">{{ __('shifts.cash_out_usd') }}</dt><dd class="font-medium"><x-ltr>${{ number_format($totals['cash_out_usd'], 2) }}</x-ltr></dd></div>
                    <div><dt class="text-gray-500">{{ __('shifts.cash_out_lbp') }}</dt><dd class="font-medium"><x-ltr>{{ number_format($totals['cash_out_lbp']) }}</x-ltr></dd></div>
                @endif
            </dl>

            @if ($expenses->isNotEmpty())
                <div class="mb-6">
                    <h4 class="text-sm font-semibold mb-2">{{ __('shifts.drawer_expenses') }}</h4>
                    <ul class="text-sm divide-y border rounded">
                        @foreach ($expenses as $e)
                            <li class="flex justify-between gap-3 p-2">
                                <span>{{ $e->category?->localName() }}@if ($e->payee) · {{ $e->payee }}@endif</span>
                                <x-ltr>{{ $e->currency === 'lbp' ? number_format($e->enteredAmount()).' '.__('shifts.lbp') : '$'.number_format($e->enteredAmount(), 2) }}</x-ltr>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('shifts.close.store') }}" class="space-y-5" x-data="denomCounter({
                usd: [100, 50, 20, 10, 5, 1],
                lbp: [100000, 50000, 20000, 10000, 5000, 1000],
            })">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-3">
                        <div class="flex justify-between items-baseline">
                            <h3 class="font-semibold text-sm">{{ __('shifts.count_usd') }}</h3>
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
                            <h3 class="font-semibold text-sm">{{ __('shifts.count_lbp') }}</h3>
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
                        <x-input-label for="closing_cash_usd" :value="__('shifts.counted_usd')" />
                        <x-text-input id="closing_cash_usd" name="closing_cash_usd" type="number" step="0.01" min="0" dir="ltr"
                                      :value="old('closing_cash_usd', 0)" required class="block mt-1 w-full"
                                      x-bind:value="usdTotal() || {{ old('closing_cash_usd', 0) }}"
                                      x-bind:readonly="hasUsdDenom()" />
                        <p class="text-xs text-gray-500 mt-1" x-show="hasUsdDenom()">{{ __('shifts.auto_calc') }}</p>
                    </div>
                    <div>
                        <x-input-label for="closing_cash_lbp" :value="__('shifts.counted_lbp')" />
                        <x-text-input id="closing_cash_lbp" name="closing_cash_lbp" type="number" step="any" min="0" dir="ltr"
                                      :value="old('closing_cash_lbp', 0)" required class="block mt-1 w-full"
                                      x-bind:value="lbpTotal() || {{ old('closing_cash_lbp', 0) }}"
                                      x-bind:readonly="hasLbpDenom()" />
                    </div>
                </div>

                <div>
                    <x-input-label for="notes" :value="__('shifts.notes_optional')" />
                    <textarea id="notes" name="notes" dir="auto" rows="2" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">{{ old('notes') }}</textarea>
                </div>

                <div class="flex items-center justify-end">
                    <x-primary-button class="!bg-red-600 hover:!bg-red-700">{{ __('nav.close_shift') }}</x-primary-button>
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
