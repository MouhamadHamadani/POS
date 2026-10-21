<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $title }}</h2></x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @include('reports._filters')

        <div class="bg-white rounded-lg shadow-card p-5">
            <canvas id="salesChart" height="80"
                    data-label="{{ __('reports.revenue_usd') }}"
                    data-labels='@json(collect($rows)->pluck('day'))'
                    data-values='@json(collect($rows)->pluck('total')->map(fn($v) => round((float) $v, 2)))'></canvas>
        </div>

        <div class="bg-white rounded-lg shadow-card overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-start">{{ __('reports.cols.day') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.transactions') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.subtotal') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.discount') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.tax') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($rows as $r)
                    <tr>
                        {{-- One row per day, not per receipt: the day opens that day's completed sales. --}}
                        <td class="p-3"><a href="{{ route('sales.index', ['from' => $r['day'], 'to' => $r['day'], 'status' => \App\Models\Sale::STATUS_COMPLETED]) }}" class="text-brand-700 hover:underline"><x-ltr>{{ $r['day'] }}</x-ltr></a></td>
                        <td class="p-3 text-end"><x-ltr>{{ $r['txn_count'] }}</x-ltr></td>
                        <td class="p-3 text-end"><x-ltr>${{ number_format((float) $r['subtotal'], 2) }}</x-ltr></td>
                        <td class="p-3 text-end text-gray-500"><x-ltr>${{ number_format((float) $r['discount'], 2) }}</x-ltr></td>
                        <td class="p-3 text-end text-gray-500"><x-ltr>${{ number_format((float) $r['tax'], 2) }}</x-ltr></td>
                        <td class="p-3 text-end font-medium"><x-ltr>${{ number_format((float) $r['total'], 2) }}</x-ltr></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-10 text-center text-gray-500">{{ __('reports.no_sales_range') }}</td></tr>
                @endforelse
                </tbody>
                <tfoot class="bg-gray-50 font-bold border-t-2">
                    <tr>
                        <td class="p-3">{{ __('reports.cols.totals') }}</td>
                        <td class="p-3 text-end"><x-ltr>{{ $totals['txn_count'] }}</x-ltr></td>
                        <td class="p-3 text-end"><x-ltr>${{ number_format((float) $totals['subtotal'], 2) }}</x-ltr></td>
                        <td class="p-3 text-end"><x-ltr>${{ number_format((float) $totals['discount'], 2) }}</x-ltr></td>
                        <td class="p-3 text-end"><x-ltr>${{ number_format((float) $totals['tax'], 2) }}</x-ltr></td>
                        <td class="p-3 text-end text-brand-700"><x-ltr>${{ number_format((float) $totals['total'], 2) }}</x-ltr></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- Read-only breakdowns of the same sales as the table above. --}}
    <div class="pb-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-lg shadow-card overflow-hidden">
            <div class="px-4 pt-4">
                <h3 class="text-sm font-semibold">{{ __('reports.by_payment') }}</h3>
                <p class="text-xs text-gray-500">{{ __('reports.by_payment_note') }}</p>
            </div>
            <table class="min-w-full text-sm mt-2">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-start">{{ __('reports.cols.method') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.transactions') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.amount_usd') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.share_pct') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @foreach ($byPayment as $p)
                    <tr>
                        <td class="p-3">{{ \App\Support\EnumLabel::get('payment_method', $p['method']) }}</td>
                        <td class="p-3 text-end"><x-ltr>{{ $p['sales'] }}</x-ltr></td>
                        <td class="p-3 text-end font-medium"><x-ltr>${{ number_format($p['usd'], 2) }}</x-ltr></td>
                        <td class="p-3 text-end text-gray-500"><x-ltr>{{ number_format($p['share'], 1) }}%</x-ltr></td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot class="bg-gray-50 font-bold border-t-2">
                    <tr>
                        <td class="p-3" colspan="2">{{ __('reports.cols.totals') }}</td>
                        <td class="p-3 text-end text-brand-700"><x-ltr>${{ number_format(array_sum(array_column($byPayment, 'usd')), 2) }}</x-ltr></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @if ($byChannel !== null)
            <div class="bg-white rounded-lg shadow-card overflow-hidden">
                <div class="px-4 pt-4">
                    <h3 class="text-sm font-semibold">{{ __('reports.by_channel') }}</h3>
                </div>
                <table class="min-w-full text-sm mt-2">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="p-3 text-start">{{ __('reports.cols.channel') }}</th>
                            <th class="p-3 text-end">{{ __('reports.cols.transactions') }}</th>
                            <th class="p-3 text-end">{{ __('reports.cols.amount_usd') }}</th>
                            <th class="p-3 text-end">{{ __('reports.cols.share_pct') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                    @foreach ($byChannel as $c)
                        <tr>
                            <td class="p-3">{{ \App\Support\EnumLabel::get('sale_channel', $c['channel']) }}</td>
                            <td class="p-3 text-end"><x-ltr>{{ $c['sales'] }}</x-ltr></td>
                            <td class="p-3 text-end font-medium"><x-ltr>${{ number_format($c['usd'], 2) }}</x-ltr></td>
                            <td class="p-3 text-end text-gray-500"><x-ltr>{{ number_format($c['share'], 1) }}%</x-ltr></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @push('scripts')
    @vite('resources/js/charts.js')
    @endpush
</x-app-layout>
