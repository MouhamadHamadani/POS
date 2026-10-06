<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $title }}</h2></x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

        <form method="GET" class="bg-white rounded-lg shadow-card p-4 flex flex-wrap gap-3 items-end text-sm">
            <div>
                <label class="block text-xs text-gray-500">{{ __('common.filter') }}</label>
                <select name="status_filter" class="border-gray-300 rounded text-sm">
                    <option value="">{{ __('reports.filter_all') }}</option>
                    <option value="low" @selected(($status_filter ?? '') === 'low')>{{ __('reports.filter_low') }}</option>
                    <option value="out" @selected(($status_filter ?? '') === 'out')>{{ __('reports.filter_out') }}</option>
                </select>
            </div>
            <button class="px-3 py-1.5 bg-gray-800 text-white rounded text-xs">{{ __('reports.apply') }}</button>
            @error('demo')
                <div class="w-full p-2 bg-amber-50 text-amber-800 rounded text-xs">{{ $message }}</div>
            @enderror
            @if (\App\Support\Demo::enabled())
                <span class="px-3 py-1.5 bg-gray-200 text-gray-500 rounded text-xs" title="{{ __('reports.exports_disabled_title') }}">
                    {{ __('reports.export_disabled') }}
                </span>
            @else
                <a href="{{ url()->current() }}?{{ http_build_query(array_merge(request()->query(), ['format' => 'pdf'])) }}"
                   class="px-3 py-1.5 bg-red-600 text-white rounded text-xs">{{ __('reports.export_pdf') }}</a>
                <a href="{{ url()->current() }}?{{ http_build_query(array_merge(request()->query(), ['format' => 'xlsx'])) }}"
                   class="px-3 py-1.5 bg-green-600 text-white rounded text-xs">{{ __('reports.export_format', ['format' => strtoupper(\App\Support\Spreadsheet::extension())]) }}</a>
            @endif
            <a href="{{ route('reports.index') }}" class="text-xs text-gray-500 hover:underline ml-auto"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('reports.all_reports') }}</a>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('reports.value_cost') }}</div>
                <div class="text-2xl font-bold text-brand-700 mt-1"><x-ltr>${{ number_format((float) $totals['value_cost'], 2) }}</x-ltr></div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('reports.value_retail') }}</div>
                <div class="text-2xl font-bold text-accent mt-1"><x-ltr>${{ number_format((float) $totals['value_retail'], 2) }}</x-ltr></div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('reports.potential_margin') }}</div>
                <div class="text-2xl font-bold text-success mt-1"><x-ltr>${{ number_format((float) $totals['margin'], 2) }}</x-ltr></div>
                <div class="text-xs text-gray-500 mt-1">{{ __('reports.tracked_products', ['count' => $totals['count']]) }}</div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-card overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-left">{{ __('dashboard.product') }}</th>
                        <th class="p-3 text-left">SKU</th>
                        <th class="p-3 text-right">{{ __('reports.cols.stock') }}</th>
                        <th class="p-3 text-right">{{ __('dashboard.min') }}</th>
                        <th class="p-3 text-right">{{ __('purchases.cost') }}</th>
                        <th class="p-3 text-right">{{ __('reports.cols.cost_value') }}</th>
                        <th class="p-3 text-right">{{ __('reports.cols.retail_value') }}</th>
                        <th class="p-3 text-center">{{ __('common.status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($rows as $r)
                    <tr>
                        <td class="p-3">{{ $r['local_name'] ?? $r['name'] }}</td>
                        <td class="p-3 text-xs text-gray-500"><x-ltr>{{ $r['sku'] }}</x-ltr></td>
                        <td class="p-3 text-right"><x-ltr>{{ rtrim(rtrim(number_format((float) $r['stock_qty'], 4, '.', ''), '0'), '.') }}</x-ltr> {{ $r['unit'] }}</td>
                        <td class="p-3 text-right text-gray-500"><x-ltr>{{ rtrim(rtrim(number_format((float) $r['min_stock'], 4, '.', ''), '0'), '.') }}</x-ltr></td>
                        <td class="p-3 text-right text-xs"><x-ltr>${{ number_format((float) $r['cost_usd'], 4) }}</x-ltr></td>
                        <td class="p-3 text-right"><x-ltr>${{ number_format((float) $r['stock_value_cost'], 2) }}</x-ltr></td>
                        <td class="p-3 text-right"><x-ltr>${{ number_format((float) $r['stock_value_retail'], 2) }}</x-ltr></td>
                        <td class="p-3 text-center">
                            @if ($r['status'] === 'out')
                                <span class="text-xs px-2 py-0.5 rounded bg-red-100 text-red-700">{{ __('enums.stock_status.out') }}</span>
                            @elseif ($r['status'] === 'low')
                                <span class="text-xs px-2 py-0.5 rounded bg-orange-100 text-orange-700">{{ __('enums.stock_status.low') }}</span>
                            @else
                                <span class="text-xs px-2 py-0.5 rounded bg-green-100 text-green-700">{{ __('enums.stock_status.ok') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="p-10 text-center text-gray-500">{{ __('reports.no_products_match') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
