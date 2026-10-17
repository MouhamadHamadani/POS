<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $title }}</h2></x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @include('reports._filters')

        <div class="bg-white rounded-lg shadow-card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-start">{{ __('reports.cols.date') }}</th>
                        <th class="p-3 text-start">{{ __('reports.cols.receipt') }}</th>
                        <th class="p-3 text-start">{{ __('reports.cols.product') }}</th>
                        <th class="p-3 text-start">{{ __('reports.cols.cashier') }}</th>
                        <th class="p-3 text-start">{{ __('reports.cols.approver') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.units') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.list_price') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.charged_price') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.difference') }}</th>
                        <th class="p-3 text-start">{{ __('reports.cols.reason') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($rows as $r)
                    <tr>
                        <td class="p-3 whitespace-nowrap"><x-ltr>{{ $r['date'] }}</x-ltr></td>
                        <td class="p-3 whitespace-nowrap"><x-ltr>{{ $r['receipt'] }}</x-ltr></td>
                        <td class="p-3">{{ $r['local_name'] }}</td>
                        <td class="p-3">{{ $r['cashier'] ?? '—' }}</td>
                        <td class="p-3">{{ $r['approver'] ?? '—' }}</td>
                        <td class="p-3 text-end"><x-ltr>{{ rtrim(rtrim(number_format($r['qty'], 4, '.', ''), '0'), '.') }}</x-ltr></td>
                        <td class="p-3 text-end text-gray-500"><x-ltr>${{ number_format($r['list_price'], 2) }}</x-ltr></td>
                        <td class="p-3 text-end"><x-ltr>${{ number_format($r['charged_price'], 2) }}</x-ltr></td>
                        <td class="p-3 text-end font-medium {{ $r['difference'] < 0 ? 'text-danger' : 'text-success' }}"><x-ltr>{{ $r['difference'] < 0 ? '-' : '+' }}${{ number_format(abs($r['difference']), 2) }}</x-ltr></td>
                        <td class="p-3" dir="auto">{{ $r['reason'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="p-10 text-center text-gray-500">{{ __('reports.no_price_overrides') }}</td></tr>
                @endforelse
                </tbody>
                @if (count($rows))
                <tfoot class="bg-gray-50 font-semibold">
                    <tr>
                        <td class="p-3" colspan="8">{{ __('reports.cols.net_difference') }}</td>
                        <td class="p-3 text-end {{ $totals['net_difference'] < 0 ? 'text-danger' : 'text-success' }}"><x-ltr>{{ $totals['net_difference'] < 0 ? '-' : '+' }}${{ number_format(abs($totals['net_difference']), 2) }}</x-ltr></td>
                        <td class="p-3"></td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>
</x-app-layout>
