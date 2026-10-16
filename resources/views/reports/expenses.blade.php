<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $title }}</h2></x-slot>

    @php
        $usd = fn ($v) => '$'.number_format((float) $v, 2);
        $lbp = fn ($v) => number_format((float) $v).' '.__('shifts.lbp');
        $monthLabel = fn ($m) => \Carbon\Carbon::createFromFormat('Y-m', $m)->format('Y-m');
    @endphp

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @include('reports._filters')

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ \App\Support\EnumLabel::get('expense_kind', 'fixed') }}</div>
                <div class="text-2xl font-bold text-gray-800 mt-1"><x-ltr>{{ $usd($fixed) }}</x-ltr></div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ \App\Support\EnumLabel::get('expense_kind', 'running') }}</div>
                <div class="text-2xl font-bold text-gray-800 mt-1"><x-ltr>{{ $usd($running) }}</x-ltr></div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('expenses.total') }}</div>
                <div class="text-2xl font-bold text-brand-700 mt-1"><x-ltr>{{ $usd($total) }}</x-ltr></div>
                <div class="text-xs text-gray-500 mt-1"><x-ltr>≈ {{ $lbp($total_lbp) }}</x-ltr></div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-card overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-start">{{ __('reports.cols.category') }}</th>
                        <th class="p-3 text-start">{{ __('expenses.cols.kind') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.amount_usd') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.share_pct') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($categories as $c)
                    <tr>
                        <td class="p-3">{{ $c['local_name'] }}</td>
                        <td class="p-3 text-xs text-gray-500">{{ \App\Support\EnumLabel::get('expense_kind', $c['kind']) }}</td>
                        <td class="p-3 text-end"><x-ltr>{{ $usd($c['total']) }}</x-ltr></td>
                        <td class="p-3 text-end text-gray-600"><x-ltr>{{ number_format($c['pct'], 1) }}%</x-ltr></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-10 text-center text-gray-500">{{ __('expenses.empty') }}</td></tr>
                @endforelse
                </tbody>
                @if (count($categories))
                    <tfoot class="bg-gray-50 font-semibold">
                        <tr><td class="p-3" colspan="2">{{ \App\Support\EnumLabel::get('expense_kind', 'fixed') }}</td><td class="p-3 text-end"><x-ltr>{{ $usd($fixed) }}</x-ltr></td><td></td></tr>
                        <tr><td class="p-3" colspan="2">{{ \App\Support\EnumLabel::get('expense_kind', 'running') }}</td><td class="p-3 text-end"><x-ltr>{{ $usd($running) }}</x-ltr></td><td></td></tr>
                        <tr><td class="p-3" colspan="2">{{ __('reports.cols.totals') }}</td><td class="p-3 text-end"><x-ltr>{{ $usd($total) }}</x-ltr></td><td></td></tr>
                    </tfoot>
                @endif
            </table>
        </div>

        @if (count($months))
            <h3 class="font-semibold text-sm text-gray-700">{{ __('expenses.by_month') }}</h3>
            <div class="bg-white rounded-lg shadow-card overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="p-3 text-start">{{ __('reports.cols.category') }}</th>
                            @foreach ($months as $m)<th class="p-3 text-end"><x-ltr>{{ $monthLabel($m) }}</x-ltr></th>@endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                    @foreach ($categories as $c)
                        <tr>
                            <td class="p-3">{{ $c['local_name'] }}</td>
                            @foreach ($months as $m)
                                <td class="p-3 text-end"><x-ltr>{{ $usd($monthly[$c['id']][$m] ?? 0) }}</x-ltr></td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50 font-semibold">
                        <tr>
                            <td class="p-3">{{ __('reports.cols.totals') }}</td>
                            @foreach ($months as $m)<td class="p-3 text-end"><x-ltr>{{ $usd($month_totals[$m]) }}</x-ltr></td>@endforeach
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
