<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $title }}</h2></x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

        <form method="GET" class="bg-white rounded-lg shadow-card p-4 flex flex-wrap gap-3 items-end text-sm">
            <div>
                <label class="block text-xs text-gray-500">Filter</label>
                <select name="status_filter" class="border-gray-300 rounded text-sm">
                    <option value="">All tracked products</option>
                    <option value="low" @selected(($status_filter ?? '') === 'low')>Low stock only</option>
                    <option value="out" @selected(($status_filter ?? '') === 'out')>Out of stock only</option>
                </select>
            </div>
            <button class="px-3 py-1.5 bg-gray-800 text-white rounded text-xs">Apply</button>
            <a href="{{ url()->current() }}?{{ http_build_query(array_merge(request()->query(), ['format' => 'pdf'])) }}"
               class="px-3 py-1.5 bg-red-600 text-white rounded text-xs">Export PDF</a>
            <a href="{{ url()->current() }}?{{ http_build_query(array_merge(request()->query(), ['format' => 'xlsx'])) }}"
               class="px-3 py-1.5 bg-green-600 text-white rounded text-xs">Export XLSX</a>
            <a href="{{ route('reports.index') }}" class="text-xs text-gray-500 hover:underline ml-auto">← All reports</a>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">Total stock value (cost)</div>
                <div class="text-2xl font-bold text-brand-700 mt-1">${{ number_format((float) $totals['value_cost'], 2) }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">Total stock value (retail)</div>
                <div class="text-2xl font-bold text-accent mt-1">${{ number_format((float) $totals['value_retail'], 2) }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">Potential margin</div>
                <div class="text-2xl font-bold text-success mt-1">${{ number_format((float) $totals['margin'], 2) }}</div>
                <div class="text-xs text-gray-500 mt-1">{{ $totals['count'] }} tracked products</div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-card overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-left">Product</th>
                        <th class="p-3 text-left">SKU</th>
                        <th class="p-3 text-right">Stock</th>
                        <th class="p-3 text-right">Min</th>
                        <th class="p-3 text-right">Cost</th>
                        <th class="p-3 text-right">Cost Value</th>
                        <th class="p-3 text-right">Retail Value</th>
                        <th class="p-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($rows as $r)
                    <tr>
                        <td class="p-3">{{ $r['name'] }}</td>
                        <td class="p-3 text-xs text-gray-500">{{ $r['sku'] }}</td>
                        <td class="p-3 text-right">{{ rtrim(rtrim(number_format((float) $r['stock_qty'], 4, '.', ''), '0'), '.') }} {{ $r['unit'] }}</td>
                        <td class="p-3 text-right text-gray-500">{{ rtrim(rtrim(number_format((float) $r['min_stock'], 4, '.', ''), '0'), '.') }}</td>
                        <td class="p-3 text-right text-xs">${{ number_format((float) $r['cost_usd'], 4) }}</td>
                        <td class="p-3 text-right">${{ number_format((float) $r['stock_value_cost'], 2) }}</td>
                        <td class="p-3 text-right">${{ number_format((float) $r['stock_value_retail'], 2) }}</td>
                        <td class="p-3 text-center">
                            @if ($r['status'] === 'out')
                                <span class="text-xs px-2 py-0.5 rounded bg-red-100 text-red-700">Out</span>
                            @elseif ($r['status'] === 'low')
                                <span class="text-xs px-2 py-0.5 rounded bg-orange-100 text-orange-700">Low</span>
                            @else
                                <span class="text-xs px-2 py-0.5 rounded bg-green-100 text-green-700">OK</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="p-10 text-center text-gray-500">No products match.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
