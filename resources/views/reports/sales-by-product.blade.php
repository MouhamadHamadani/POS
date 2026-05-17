<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $title }}</h2></x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @include('reports._filters')

        <div class="bg-white rounded-lg shadow-card overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-left">Product</th>
                        <th class="p-3 text-right">Units</th>
                        <th class="p-3 text-right">Revenue</th>
                        <th class="p-3 text-right">COGS</th>
                        <th class="p-3 text-right">Profit</th>
                        <th class="p-3 text-right">Margin %</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($rows as $r)
                    <tr>
                        <td class="p-3">{{ $r['product_name'] }}</td>
                        <td class="p-3 text-right">{{ rtrim(rtrim(number_format((float) $r['units'], 4, '.', ''), '0'), '.') }}</td>
                        <td class="p-3 text-right">${{ number_format((float) $r['revenue'], 2) }}</td>
                        <td class="p-3 text-right text-gray-500">${{ number_format((float) $r['cogs'], 2) }}</td>
                        <td class="p-3 text-right font-medium {{ $r['profit'] > 0 ? 'text-success' : 'text-danger' }}">${{ number_format((float) $r['profit'], 2) }}</td>
                        <td class="p-3 text-right">{{ number_format($r['margin_pct'], 1) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-10 text-center text-gray-500">No product sales in this range.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
