<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $title }}</h2></x-slot>

    <div class="py-6 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @include('reports._filters')

        <div class="bg-white rounded-lg shadow-card p-6">
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    <tr><td class="py-3">Gross Revenue</td><td class="py-3 text-right font-medium">${{ number_format($gross_revenue, 2) }}</td></tr>
                    <tr><td class="py-3 text-gray-500 pl-6">– Discounts</td><td class="py-3 text-right text-gray-500">${{ number_format($discounts, 2) }}</td></tr>
                    <tr><td class="py-3 text-gray-500 pl-6">+ Tax collected (held separately)</td><td class="py-3 text-right text-gray-500">${{ number_format($tax_collected, 2) }}</td></tr>
                    <tr class="border-t-2 bg-gray-50"><td class="py-3 font-semibold">Net Revenue (incl. tax)</td><td class="py-3 text-right font-bold text-brand-700">${{ number_format($net_revenue, 2) }}</td></tr>
                    <tr><td class="py-3 text-gray-500 pl-6">– COGS</td><td class="py-3 text-right text-gray-500">${{ number_format($cogs, 2) }}</td></tr>
                    <tr class="border-t-2 bg-gray-50"><td class="py-3 font-bold">Gross Profit</td><td class="py-3 text-right font-bold {{ $gross_profit > 0 ? 'text-success' : 'text-danger' }}">${{ number_format($gross_profit, 2) }}</td></tr>
                    <tr><td class="py-3">Margin</td><td class="py-3 text-right">{{ number_format($margin_pct, 1) }}%</td></tr>
                    <tr><td class="py-3">Transactions</td><td class="py-3 text-right">{{ $txn_count }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
