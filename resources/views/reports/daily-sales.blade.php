<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $title }}</h2></x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @include('reports._filters')

        <div class="bg-white rounded-lg shadow-card p-5">
            <canvas id="salesChart" height="80"></canvas>
        </div>

        <div class="bg-white rounded-lg shadow-card overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-left">Day</th>
                        <th class="p-3 text-right">Transactions</th>
                        <th class="p-3 text-right">Subtotal</th>
                        <th class="p-3 text-right">Discount</th>
                        <th class="p-3 text-right">Tax</th>
                        <th class="p-3 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($rows as $r)
                    <tr>
                        <td class="p-3">{{ $r['day'] }}</td>
                        <td class="p-3 text-right">{{ $r['txn_count'] }}</td>
                        <td class="p-3 text-right">${{ number_format((float) $r['subtotal'], 2) }}</td>
                        <td class="p-3 text-right text-gray-500">${{ number_format((float) $r['discount'], 2) }}</td>
                        <td class="p-3 text-right text-gray-500">${{ number_format((float) $r['tax'], 2) }}</td>
                        <td class="p-3 text-right font-medium">${{ number_format((float) $r['total'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-10 text-center text-gray-500">No sales in this date range.</td></tr>
                @endforelse
                </tbody>
                <tfoot class="bg-gray-50 font-bold border-t-2">
                    <tr>
                        <td class="p-3">Totals</td>
                        <td class="p-3 text-right">{{ $totals['txn_count'] }}</td>
                        <td class="p-3 text-right">${{ number_format((float) $totals['subtotal'], 2) }}</td>
                        <td class="p-3 text-right">${{ number_format((float) $totals['discount'], 2) }}</td>
                        <td class="p-3 text-right">${{ number_format((float) $totals['tax'], 2) }}</td>
                        <td class="p-3 text-right text-brand-700">${{ number_format((float) $totals['total'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    @push('scripts')
    <script type="module">
        import Chart from 'chart.js/auto';
        const labels = @json(collect($rows)->pluck('day'));
        const data = @json(collect($rows)->pluck('total')->map(fn($v) => round((float) $v, 2)));
        new Chart(document.getElementById('salesChart').getContext('2d'), {
            type: 'line',
            data: { labels, datasets: [{ label: 'Revenue (USD)', data, borderColor: '#1B3A6B', backgroundColor: 'rgba(41,128,185,0.15)', tension: 0.2, fill: true }] },
            options: { responsive: true, plugins: { legend: { display: false } } }
        });
    </script>
    @endpush
</x-app-layout>
