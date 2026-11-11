<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $title }}</h2></x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @include('reports._filters')

        <div class="bg-white rounded-lg shadow-card overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-start">{{ __('dashboard.product') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.units') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.revenue_ex_vat') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.cogs') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.profit') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.margin_pct') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($rows as $r)
                    <tr>
                        <td class="p-3">{{ $r['local_name'] ?? $r['product_name'] }}</td>
                        <td class="p-3 text-end">
                            <x-ltr>{{ rtrim(rtrim(number_format((float) $r['units'], 4, '.', ''), '0'), '.') }}</x-ltr>
                            {{-- Packaging: a product sold by the package or the carton counts in packs, with the split underneath. --}}
                            @if (! empty($r['has_package']) || (float) ($r['packages'] ?? 0) > 0 || (float) ($r['cartons'] ?? 0) > 0)
                                <span class="text-xs text-gray-500">{{ __('packaging.report.packs') }}</span>
                                @if ((float) ($r['packages'] ?? 0) > 0 || (float) ($r['cartons'] ?? 0) > 0)
                                    <div class="text-xs text-gray-500">
                                        @if ((float) $r['packages'] > 0){{ __('packaging.report.packages_n', ['n' => rtrim(rtrim(number_format((float) $r['packages'], 4, '.', ''), '0'), '.')]) }}@endif
                                        @if ((float) $r['cartons'] > 0){{ __('packaging.report.cartons_n', ['n' => rtrim(rtrim(number_format((float) $r['cartons'], 4, '.', ''), '0'), '.')]) }}@endif
                                    </div>
                                @endif
                            @endif
                        </td>
                        <td class="p-3 text-end"><x-ltr>${{ number_format((float) $r['revenue'], 2) }}</x-ltr></td>
                        <td class="p-3 text-end text-gray-500"><x-ltr>${{ number_format((float) $r['cogs'], 2) }}</x-ltr></td>
                        <td class="p-3 text-end font-medium {{ $r['profit'] > 0 ? 'text-success' : 'text-danger' }}"><x-ltr>${{ number_format((float) $r['profit'], 2) }}</x-ltr></td>
                        <td class="p-3 text-end"><x-ltr>{{ number_format($r['margin_pct'], 1) }}%</x-ltr></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-10 text-center text-gray-500">{{ __('reports.no_product_sales') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
