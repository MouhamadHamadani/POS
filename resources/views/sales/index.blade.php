<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('sales.title') }}</h2></x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4" x-data="salesReprint(@js($desktop))">

        @include('sales._reprint_error')

        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="bg-white rounded-lg shadow-sm p-4">
            <form method="GET" action="{{ route('sales.index') }}" class="space-y-2 text-sm">
                <div class="grid grid-cols-1 md:grid-cols-4 lg:grid-cols-7 gap-2 items-end">
                    <div class="md:col-span-2">
                        <label class="block text-xs text-gray-500">{{ __('sales.search') }}</label>
                        <input type="text" name="q" dir="auto" value="{{ $f['q'] }}" placeholder="{{ __('sales.search_placeholder') }}" class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500">{{ __('reports.from') }}</label>
                        <input type="date" name="from" dir="ltr" value="{{ $f['defaulted'] ? '' : $f['from']?->format('Y-m-d') }}" class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500">{{ __('reports.to') }}</label>
                        <input type="date" name="to" dir="ltr" value="{{ $f['defaulted'] ? '' : $f['to']?->format('Y-m-d') }}" class="w-full border-gray-300 rounded text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500">{{ __('common.status') }}</label>
                        <select name="status" class="w-full border-gray-300 rounded text-sm">
                            <option value="">{{ __('common.all') }}</option>
                            @foreach (\App\Models\Sale::STATUSES as $st)
                                <option value="{{ $st }}" @selected($f['status'] === $st)>{{ \App\Support\EnumLabel::get('sale_status', $st) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500">{{ __('receipt.payment') }}</label>
                        <select name="payment_method" class="w-full border-gray-300 rounded text-sm">
                            <option value="">{{ __('common.all') }}</option>
                            @foreach (\App\Services\SaleHistoryService::PAYMENT_FILTERS as $m)
                                <option value="{{ $m }}" @selected($f['payment_method'] === $m)>{{ \App\Support\EnumLabel::get('payment_method', $m) }}</option>
                            @endforeach
                        </select>
                    </div>
                    @feature('sales_channels')
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('sales.channel') }}</label>
                            <select name="channel" class="w-full border-gray-300 rounded text-sm">
                                <option value="">{{ __('common.all') }}</option>
                                @foreach (\App\Models\Sale::CHANNELS as $ch)
                                    <option value="{{ $ch }}" @selected($f['channel'] === $ch)>{{ \App\Support\EnumLabel::get('sale_channel', $ch) }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endfeature
                    {{-- Only managerial roles can see anyone else's sales, so only they get this filter. --}}
                    @if ($cashiers->isNotEmpty())
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('receipt.cashier') }}</label>
                            <select name="user_id" class="w-full border-gray-300 rounded text-sm">
                                <option value="">{{ __('common.all') }}</option>
                                @foreach ($cashiers as $c)
                                    <option value="{{ $c->id }}" @selected($f['user_id'] === $c->id)>{{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button class="px-3 py-1.5 bg-gray-800 text-white rounded text-xs">{{ __('reports.apply') }}</button>
                    <a href="{{ route('sales.index') }}" class="px-3 py-1.5 bg-gray-100 rounded text-xs">{{ __('stock.reset') }}</a>
                    @if ($f['defaulted'])
                        <span class="text-xs text-gray-500">{{ __('sales.default_range_note') }}</span>
                    @endif
                </div>
            </form>
        </div>

        <div class="flex flex-wrap gap-x-6 gap-y-1 px-1 text-sm text-gray-600">
            <span>{{ trans_choice('sales.summary_count', $summary['count']) }}</span>
            <span>{{ __('sales.summary_total') }}: <x-ltr class="font-semibold text-gray-800">${{ number_format($summary['total'], 2) }}</x-ltr></span>
        </div>

        <div class="bg-white rounded-lg shadow-card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-start">{{ __('invoice.number') }}</th>
                        <th class="p-3 text-start">{{ __('receipt.date') }}</th>
                        <th class="p-3 text-start">{{ __('receipt.customer') }}</th>
                        <th class="p-3 text-start">{{ __('receipt.cashier') }}</th>
                        <th class="p-3 text-start">{{ __('receipt.payment') }}</th>
                        @feature('sales_channels')<th class="p-3 text-start">{{ __('sales.channel') }}</th>@endfeature
                        <th class="p-3 text-end">{{ __('dashboard.total') }}</th>
                        <th class="p-3 text-center">{{ __('common.status') }}</th>
                        <th class="p-3 text-end">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($sales as $s)
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 font-mono text-xs">
                            {{-- The filter query string rides along so "Back to sales" lands on the same page of the same search. --}}
                            <a href="{{ route('sales.show', ['sale' => $s->id] + request()->query()) }}" class="text-brand-700 hover:underline"><x-ltr>{{ $s->receipt_number }}</x-ltr></a>
                        </td>
                        <td class="p-3 text-xs text-gray-600 whitespace-nowrap"><x-ltr>{{ $s->created_at->format('Y-m-d H:i') }}</x-ltr></td>
                        <td class="p-3">{{ $s->customer?->name ?? __('returns.walk_in') }}</td>
                        <td class="p-3">{{ $s->user?->name ?? '—' }}</td>
                        <td class="p-3 text-xs">{{ $s->paymentMethodLabel() }}</td>
                        @feature('sales_channels')
                            <td class="p-3 text-xs whitespace-nowrap">
                                <span class="px-1.5 py-0.5 rounded {{ $s->isOnline() ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-600' }}">{{ $s->channelLabel() }}</span>
                                @if ($s->channel_source)<span class="text-gray-500">· {{ $s->channelSourceLabel() }}</span>@endif
                            </td>
                        @endfeature
                        <td class="p-3 text-end whitespace-nowrap">
                            <div class="font-medium"><x-ltr>${{ number_format((float) $s->total_usd, 2) }}</x-ltr></div>
                            @if ((float) $s->total_lbp > 0)
                                <div class="text-xs text-gray-500"><x-ltr>{{ number_format((float) $s->total_lbp) }}</x-ltr> {{ __('shifts.lbp') }}</div>
                            @endif
                        </td>
                        <td class="p-3 text-center"><x-sale-status :sale="$s" /></td>
                        <td class="p-3 text-end whitespace-nowrap">
                            <a href="{{ route('sales.show', ['sale' => $s->id] + request()->query()) }}" class="px-2 py-1 rounded text-xs bg-gray-100 text-gray-700 hover:bg-gray-200">{{ __('common.view') }}</a>
                            @if ($s->hasReceipt())
                                @foreach ($formats as $fmt)
                                    <button type="button" @click="print({{ $s->id }}, '{{ $fmt }}', 'history')"
                                            title="{{ $fmt === 'a4' ? __('sales.reprint_a4') : __('sales.reprint_receipt') }}"
                                            class="px-2 py-1 rounded text-xs {{ $ask || $loop->first ? 'bg-brand-700 text-white hover:bg-brand-800' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">{{ $fmt === 'a4' ? __('sales.a4_short') : __('sales.receipt_short') }}</button>
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ feature('sales_channels') ? 9 : 8 }}" class="p-10 text-center text-gray-500">{{ __('sales.none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $sales->links() }}</div>
    </div>

    @include('sales._reprint')
</x-app-layout>
