{{-- A customer's debt book: record a payment, then Open debts / Payments / Statement. Shared by /debts/{customer}
     and the customer page. Needs $customer, $open, $payments, $statement, $from, $to, $debt, $overdue, $tab, $canManage,
     optionally $slipPayment (the repayment just recorded). --}}
@php
    $money = fn ($v) => '$'.number_format((float) $v, 2);
    $viewer = auth()->user();
    $rate = (float) \App\Models\Setting::get('exchange_rate', 90000);
    $desktop = app(\App\Services\ReceiptPrintService::class)->inNativeShell();
    $autoPrint = (bool) \App\Models\Setting::get('auto_print', false);
    $slipPayment = $slipPayment ?? null;
@endphp

@if ($slipPayment)
    <div class="p-3 bg-green-50 border border-green-200 rounded text-sm flex flex-wrap items-center gap-3"
         x-data="{ msg: '' }"
         @if ($autoPrint) x-init="printDebtSlip({{ $slipPayment->id }}, 'thermal')" @endif>
        <span class="font-medium text-green-800"><x-ltr>{{ $slipPayment->number }}</x-ltr></span>
        <button type="button" @click="printDebtSlip({{ $slipPayment->id }}, 'thermal')" class="px-3 py-1.5 bg-slate-600 text-white rounded text-xs hover:bg-slate-700">{{ __('debts.print_slip') }}</button>
        <button type="button" @click="printDebtSlip({{ $slipPayment->id }}, 'a4')" class="px-3 py-1.5 bg-slate-600 text-white rounded text-xs hover:bg-slate-700">{{ __('debts.print_slip_a4') }}</button>
        <span x-show="msg" x-text="msg" class="text-red-700 text-xs"></span>
    </div>
@endif

{{-- Record a payment --}}
@if ($debt > \App\Services\CustomerLedgerService::SETTLED)
<div class="bg-white rounded-lg shadow-card p-5"
     x-data="{
        amount: '', currency: 'USD', method: 'cash', apply: 'oldest', rate: {{ $rate }}, debt: {{ $debt }},
        rows: @js($open->map(fn ($r) => ['sale_id' => $r['sale_id'], 'outstanding' => $r['outstanding'], 'amount' => ''])->values()),
        usd() { const a = Number(this.amount) || 0; return this.currency === 'LBP' ? a / this.rate : a; },
        allocated() { return this.rows.reduce((s, r) => s + (Number(r.amount) || 0), 0); },
        lbpDebt() { return Math.ceil(this.debt * this.rate / 1000) * 1000; },
        fmt(n) { return Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 }); },
        setMethod() { if (['card','wish'].includes(this.method)) this.currency = 'USD'; },
     }">
    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
        <h3 class="font-semibold text-sm">{{ __('debts.record_payment') }}</h3>
        <div class="text-xs text-gray-500">{{ __('debts.cols.outstanding') }}: <strong class="text-gray-800"><x-ltr>{{ $money($debt) }}</x-ltr></strong>
            · <x-ltr><span x-text="fmt(lbpDebt())"></span> {{ __('shifts.lbp') }}</x-ltr></div>
    </div>
    <form method="POST" action="{{ route('debts.payments.store', $customer) }}" class="space-y-3 text-sm">
        @csrf
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('debts.amount') }}</label>
                <input type="number" name="amount" step="any" min="0.01" dir="ltr" required x-model="amount"
                       :max="currency === 'LBP' ? lbpDebt() : debt" class="w-full border-gray-300 rounded text-sm" />
                <div class="text-xs text-gray-500 mt-1" x-show="currency === 'LBP' && Number(amount) > 0">
                    ≈ <x-ltr>$<span x-text="usd().toFixed(2)"></span></x-ltr> · {{ __('debts.rate') }} <x-ltr><span x-text="fmt(rate)"></span></x-ltr>
                </div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('debts.currency') }}</label>
                <select name="currency" x-model="currency" class="w-full border-gray-300 rounded text-sm">
                    <option value="USD">USD</option>
                    <option value="LBP" :disabled="['card','wish'].includes(method)">{{ __('shifts.lbp') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('customers.method') }}</label>
                <select name="method" x-model="method" @change="setMethod()" class="w-full border-gray-300 rounded text-sm" required>
                    @foreach (\App\Models\CustomerPayment::METHODS as $m)
                        <option value="{{ $m }}">{{ __("enums.debt_method.$m") }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('debts.paid_on') }}</label>
                <input type="date" name="paid_at" dir="ltr" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}"
                       @unless ($canManage) min="{{ today()->toDateString() }}" @endunless class="w-full border-gray-300 rounded text-sm" />
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('customers.reference') }}</label>
                <input type="text" name="reference" dir="ltr" maxlength="80" class="w-full border-gray-300 rounded text-sm" />
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('common.notes') }}</label>
                <input type="text" name="notes" dir="auto" maxlength="500" class="w-full border-gray-300 rounded text-sm" />
            </div>
        </div>

        <div class="border rounded p-3 space-y-2">
            <div class="text-xs font-medium text-gray-600">{{ __('debts.apply_to') }}</div>
            <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2"><input type="radio" name="apply" value="oldest" x-model="apply"> {{ __('debts.apply_oldest') }}</label>
                <label class="flex items-center gap-2"><input type="radio" name="apply" value="choose" x-model="apply"> {{ __('debts.apply_choose') }}</label>
            </div>
            <template x-if="apply === 'choose'">
                <div class="space-y-1">
                    @foreach ($open as $i => $r)
                        <div class="flex items-center gap-2 text-xs">
                            <input type="hidden" name="allocations[{{ $i }}][sale_id]" value="{{ $r['sale_id'] }}">
                            <span class="flex-1">{{ $r['is_pot'] ? __('debts.opening_balance') : $r['receipt_number'] }}
                                · {{ __('debts.cols.outstanding') }} <x-ltr>{{ $money($r['outstanding']) }}</x-ltr></span>
                            <input type="number" name="allocations[{{ $i }}][amount_usd]" step="0.01" min="0" max="{{ $r['outstanding'] }}" dir="ltr"
                                   x-model="rows[{{ $i }}].amount" placeholder="$" class="w-28 border-gray-300 rounded text-xs" />
                        </div>
                    @endforeach
                    <div class="text-xs" :class="Math.abs(allocated() - usd()) <= 0.011 ? 'text-green-700' : 'text-red-600'">
                        {{ __('debts.allocated') }}: <x-ltr>$<span x-text="allocated().toFixed(2)"></span></x-ltr> / <x-ltr>$<span x-text="usd().toFixed(2)"></span></x-ltr>
                    </div>
                </div>
            </template>
        </div>

        <button class="px-4 py-2 bg-success text-white rounded text-sm hover:bg-green-700">{{ __('customers.record_payment_btn') }}</button>
    </form>
</div>
@endif

{{-- Tabs --}}
<div x-data="{ tab: @js($tab) }" class="space-y-4">
    <div class="flex gap-1 border-b text-sm">
        @foreach (['debts' => 'tab_debts', 'payments' => 'tab_payments', 'statement' => 'tab_statement'] as $key => $label)
            <button type="button" @click="tab = '{{ $key }}'" :class="tab === '{{ $key }}' ? 'border-brand-600 text-brand-700 font-medium' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    class="px-4 py-2 -mb-px border-b-2">{{ __("debts.$label") }}</button>
        @endforeach
    </div>

    {{-- 1. Open debts --}}
    <div x-show="tab === 'debts'" class="bg-white rounded-lg shadow-card overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="p-3 text-start">{{ __('dashboard.receipt') }}</th>
                    <th class="p-3 text-start">{{ __('receipt.date') }}</th>
                    <th class="p-3 text-start">{{ __('debts.due_date') }}</th>
                    <th class="p-3 text-end">{{ __('debts.on_account') }}</th>
                    <th class="p-3 text-end">{{ __('debts.paid_so_far') }}</th>
                    <th class="p-3 text-end">{{ __('debts.cols.outstanding') }}</th>
                    <th class="p-3 text-center">{{ __('debts.status') }}</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            @forelse ($open as $r)
                @php $sale = $r['sale']; $seeItems = $sale && $viewer && $sale->canBeSeenBy($viewer); @endphp
                <tbody class="divide-y border-t" x-data="{ open: false }">
                    <tr>
                        <td class="p-3 font-mono text-xs">
                            @if ($sale) <x-receipt-link :sale="$sale" /> @else <span class="font-sans">{{ __('debts.opening_balance') }}</span> @endif
                        </td>
                        <td class="p-3 text-gray-600"><x-ltr>{{ $r['date']?->format('Y-m-d') ?? '—' }}</x-ltr></td>
                        <td class="p-3 text-gray-600">
                            <x-ltr>{{ $r['due_date']?->format('Y-m-d') ?? '—' }}</x-ltr>
                            @if ($canManage && $sale)
                                <details class="mt-1">
                                    <summary class="text-xs text-brand-700 cursor-pointer">{{ __('debts.change_due') }}</summary>
                                    <form method="POST" action="{{ route('debts.due-date', $sale) }}" class="flex gap-1 mt-1">
                                        @csrf @method('PATCH')
                                        <input type="date" name="due_date" dir="ltr" required value="{{ $r['due_date']?->format('Y-m-d') }}" class="border-gray-300 rounded text-xs" />
                                        <button class="px-2 py-1 bg-gray-100 rounded text-xs">{{ __('common.save') }}</button>
                                    </form>
                                </details>
                            @endif
                        </td>
                        <td class="p-3 text-end"><x-ltr>{{ $money($r['on_account']) }}</x-ltr></td>
                        <td class="p-3 text-end text-gray-600"><x-ltr>{{ $money($r['paid']) }}</x-ltr></td>
                        <td class="p-3 text-end font-semibold"><x-ltr>{{ $money($r['outstanding']) }}</x-ltr></td>
                        <td class="p-3 text-center">
                            @if ($r['overdue'])
                                <span class="px-2 py-0.5 rounded text-xs bg-red-50 text-red-700">{{ trans_choice('debts.days_overdue', $r['days_overdue'], ['count' => $r['days_overdue']]) }}</span>
                            @elseif ($r['due_date'])
                                <span class="px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-600">{{ $r['due_date']->isToday() ? __('debts.due_today') : __('debts.not_yet_due') }}</span>
                            @endif
                        </td>
                        <td class="p-3 text-end">
                            @if ($sale)<button type="button" @click="open = !open" class="text-xs text-brand-700 hover:underline" x-text="open ? @js(__('debts.hide')) : @js(__('debts.details'))"></button>@endif
                        </td>
                    </tr>
                    @if ($sale)
                    <tr x-show="open" x-cloak style="display: none" class="bg-gray-50">
                        <td colspan="8" class="p-3 space-y-3">
                            @if ($seeItems)
                                <table class="w-full text-xs">
                                    <thead class="text-gray-500"><tr>
                                        <th class="text-start py-1">{{ __('dashboard.product') }}</th><th class="text-end">{{ __('returns.sold') }}</th>
                                        <th class="text-end">{{ __('returns.unit_price') }}</th><th class="text-end">{{ __('dashboard.total') }}</th>
                                    </tr></thead>
                                    <tbody>
                                    @foreach ($sale->items as $item)
                                        <tr>
                                            <td class="py-1">{{ $item->localName() }}</td>
                                            <td class="text-end"><x-ltr>{{ rtrim(rtrim(number_format((float) $item->qty, 4, '.', ''), '0'), '.') }}</x-ltr></td>
                                            <td class="text-end"><x-ltr>{{ $money($item->unit_price_usd) }}</x-ltr></td>
                                            <td class="text-end"><x-ltr>{{ $money($item->line_total_usd) }}</x-ltr></td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            @else
                                <div class="text-xs text-gray-500">{{ __('debts.items_hidden') }}</div>
                            @endif
                            <div>
                                <div class="text-xs font-semibold text-gray-600 mb-1">{{ __('debts.repayments_applied') }}</div>
                                @forelse ($sale->ledgerEntries as $e)
                                    <div class="flex justify-between text-xs {{ $e->payment?->isVoided() ? 'line-through text-gray-400' : '' }}">
                                        <span><x-ltr>{{ $e->entry_date->format('Y-m-d') }}</x-ltr> · <x-ltr>{{ $e->payment?->number }}</x-ltr> · {{ $e->payment?->methodLabel() }}</span>
                                        <x-ltr>{{ $money(abs((float) $e->amount_usd)) }}</x-ltr>
                                    </div>
                                @empty
                                    <div class="text-xs text-gray-400">{{ __('debts.no_repayments') }}</div>
                                @endforelse
                            </div>
                        </td>
                    </tr>
                    @endif
                </tbody>
            @empty
                <tbody><tr><td colspan="8" class="p-8 text-center text-gray-500">{{ __('customers.no_balance') }}</td></tr></tbody>
            @endforelse
        </table>
    </div>

    {{-- 2. Payments --}}
    <div x-show="tab === 'payments'" x-cloak style="display: none" class="bg-white rounded-lg shadow-card overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="p-3 text-start">{{ __('debts.number') }}</th>
                    <th class="p-3 text-start">{{ __('receipt.date') }}</th>
                    <th class="p-3 text-start">{{ __('customers.method') }}</th>
                    <th class="p-3 text-end">{{ __('debts.amount') }}</th>
                    <th class="p-3 text-start">{{ __('debts.applied_to') }}</th>
                    <th class="p-3 text-start">{{ __('receipt.cashier') }}</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y">
            @forelse ($payments as $p)
                <tr class="{{ $p->isVoided() ? 'bg-gray-50 text-gray-400' : '' }}">
                    <td class="p-3 font-mono text-xs"><x-ltr>{{ $p->number }}</x-ltr></td>
                    <td class="p-3"><x-ltr>{{ $p->paid_at->format('Y-m-d H:i') }}</x-ltr></td>
                    <td class="p-3">{{ $p->methodLabel() }}@if ($p->reference)<div class="text-xs text-gray-500"><x-ltr>{{ $p->reference }}</x-ltr></div>@endif</td>
                    <td class="p-3 text-end">
                        <x-ltr>{{ $p->currency === 'LBP' ? number_format((float) $p->amount).' '.__('shifts.lbp') : $money($p->amount) }}</x-ltr>
                        @if ($p->currency === 'LBP')<div class="text-xs text-gray-500"><x-ltr>{{ $money($p->amount_usd) }}</x-ltr></div>@endif
                    </td>
                    <td class="p-3 text-xs">
                        @foreach ($p->entries as $e)
                            <div><x-ltr>{{ $e->sale?->receipt_number ?? __('debts.opening_balance') }}</x-ltr> · <x-ltr>{{ $money(abs((float) $e->amount_usd)) }}</x-ltr></div>
                        @endforeach
                        @if ($p->isWriteOff() && $p->notes)<div class="text-gray-500">{{ $p->notes }}</div>@endif
                    </td>
                    <td class="p-3 text-xs">{{ $p->user?->name }}</td>
                    <td class="p-3 text-end whitespace-nowrap">
                        @if ($p->isVoided())
                            <span class="px-2 py-0.5 rounded text-xs bg-red-50 text-red-700" title="{{ $p->void_reason }}">{{ __('debts.voided') }}</span>
                        @else
                            @unless ($p->isWriteOff())
                                <button type="button" @click="printDebtSlip({{ $p->id }}, 'thermal')" class="text-xs text-brand-700 hover:underline">{{ __('debts.print_slip') }}</button>
                            @endunless
                            @if ($canManage)
                                <details class="inline-block ms-2 align-top">
                                    <summary class="text-xs text-red-600 cursor-pointer">{{ __('debts.void') }}</summary>
                                    <form method="POST" action="{{ route('debts.payments.void', $p) }}" class="mt-1 flex gap-1">
                                        @csrf
                                        <input type="text" name="reason" required maxlength="300" dir="auto" placeholder="{{ __('debts.reason') }}" class="border-gray-300 rounded text-xs" />
                                        <button class="px-2 py-1 bg-red-600 text-white rounded text-xs">{{ __('debts.void') }}</button>
                                    </form>
                                </details>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="p-8 text-center text-gray-500">{{ __('debts.no_payments') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- 3. Statement --}}
    <div x-show="tab === 'statement'" x-cloak style="display: none" class="space-y-3">
        <form method="GET" class="bg-white rounded-lg shadow-card p-4 flex flex-wrap items-end gap-3 text-sm">
            <input type="hidden" name="tab" value="statement">
            <div><label class="block text-xs text-gray-500 mb-1">{{ __('reports.from') }}</label>
                <input type="date" name="from" dir="ltr" value="{{ $from->toDateString() }}" class="border-gray-300 rounded text-sm"></div>
            <div><label class="block text-xs text-gray-500 mb-1">{{ __('reports.to') }}</label>
                <input type="date" name="to" dir="ltr" value="{{ $to->toDateString() }}" class="border-gray-300 rounded text-sm"></div>
            <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm">{{ __('common.filter') }}</button>
            @php $range = ['from' => $from->toDateString(), 'to' => $to->toDateString()]; @endphp
            <div class="ms-auto flex gap-2">
                <a href="{{ route('debts.statement', ['customer' => $customer] + $range + ['auto' => 1]) }}" target="_blank" class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded text-sm">{{ __('debts.print') }}</a>
                <a href="{{ route('debts.statement', ['customer' => $customer] + $range + ['format' => 'pdf']) }}" class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded text-sm">{{ __('debts.export_pdf') }}</a>
                <a href="{{ route('debts.statement', ['customer' => $customer] + $range + ['format' => 'xlsx']) }}" class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded text-sm">{{ __('debts.export_excel') }}</a>
            </div>
        </form>
        <div class="bg-white rounded-lg shadow-card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-start">{{ __('debts.cols.date') }}</th><th class="p-3 text-start">{{ __('debts.cols.entry') }}</th>
                        <th class="p-3 text-end">{{ __('debts.cols.debit') }}</th><th class="p-3 text-end">{{ __('debts.cols.credit') }}</th>
                        <th class="p-3 text-end">{{ __('debts.cols.balance') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr class="bg-gray-50 text-gray-600"><td class="p-3"><x-ltr>{{ $from->format('Y-m-d') }}</x-ltr></td><td class="p-3">{{ __('debts.opening_balance') }}</td><td></td><td></td><td class="p-3 text-end"><x-ltr>{{ $money($statement['opening']) }}</x-ltr></td></tr>
                    @foreach ($statement['rows'] as $row)
                        <tr>
                            <td class="p-3"><x-ltr>{{ $row['date']->format('Y-m-d') }}</x-ltr></td>
                            <td class="p-3">{{ $row['type_label'] }} <span class="text-xs text-gray-500"><x-ltr>{{ $row['receipt'] ?? $row['payment'] ?? '' }}</x-ltr></span></td>
                            <td class="p-3 text-end"><x-ltr>{{ $row['debit'] ? $money($row['debit']) : '' }}</x-ltr></td>
                            <td class="p-3 text-end text-success"><x-ltr>{{ $row['credit'] ? $money($row['credit']) : '' }}</x-ltr></td>
                            <td class="p-3 text-end font-medium"><x-ltr>{{ $money($row['balance']) }}</x-ltr></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 font-semibold">
                    <tr><td class="p-3" colspan="2">{{ __('debts.closing_balance') }}</td>
                        <td class="p-3 text-end"><x-ltr>{{ $money($statement['debits']) }}</x-ltr></td>
                        <td class="p-3 text-end"><x-ltr>{{ $money($statement['credits']) }}</x-ltr></td>
                        <td class="p-3 text-end"><x-ltr>{{ $money($statement['closing']) }}</x-ltr></td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

@if ($canManage && $debt > \App\Services\CustomerLedgerService::SETTLED)
    <details class="bg-white rounded-lg shadow-card p-4 text-sm">
        <summary class="cursor-pointer font-semibold text-red-700">{{ __('debts.write_off') }}</summary>
        <form method="POST" action="{{ route('debts.write-off', $customer) }}" class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3 items-end"
              onsubmit="return confirm(@js(__('debts.write_off_confirm')))">
            @csrf
            <div><label class="block text-xs text-gray-500 mb-1">{{ __('debts.amount_usd') }}</label>
                <input type="number" name="amount_usd" step="0.01" min="0.01" max="{{ $debt }}" dir="ltr" required class="w-full border-gray-300 rounded text-sm"></div>
            <div><label class="block text-xs text-gray-500 mb-1">{{ __('debts.reason') }} *</label>
                <input type="text" name="reason" required maxlength="300" dir="auto" class="w-full border-gray-300 rounded text-sm"></div>
            <button class="px-4 py-2 bg-red-600 text-white rounded text-sm hover:bg-red-700">{{ __('debts.write_off') }}</button>
        </form>
    </details>
@endif

@once
@push('scripts')
<script>
    // The payment slip: straight to the receipt printer in the desktop app (no dialog), a popup in a browser tab.
    window.printDebtSlip = async function (id, format) {
        const url = `/debts/payments/${id}/slip?format=${format}&auto=1`;
        const desktop = @json($desktop);
        if (!desktop) { window.open(url, 'slip-' + id, format === 'a4' ? 'width=900,height=1000' : 'width=420,height=640'); return; }
        try {
            const res = await fetch(`/debts/payments/${id}/print?format=${format}`, {
                method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            });
            const data = await res.json().catch(() => ({}));
            if (data.printed) return;
            if (data.desktop === false) { window.open(url, 'slip-' + id, 'width=420,height=640'); return; }
        } catch (e) { /* counts as a failed print */ }
        alert(@js(__('debts.print_failed')));
    };
</script>
@endpush
@endonce
