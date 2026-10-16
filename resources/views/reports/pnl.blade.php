<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $title }}</h2></x-slot>

    <div class="py-6 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @include('reports._filters')

        <div class="bg-white rounded-lg shadow-card p-6">
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    <tr><td class="py-3">{{ __('reports.pnl.gross_revenue') }}</td><td class="py-3 text-end font-medium"><x-ltr>${{ number_format($gross_revenue, 2) }}</x-ltr></td></tr>
                    <tr><td class="py-3 text-gray-500 ps-6">– {{ __('reports.pnl.discounts') }}</td><td class="py-3 text-end text-gray-500"><x-ltr>${{ number_format($discounts, 2) }}</x-ltr></td></tr>
                    <tr><td class="py-3 text-gray-500 ps-6">+ {{ __('reports.pnl.tax_collected_held') }}</td><td class="py-3 text-end text-gray-500"><x-ltr>${{ number_format($tax_collected, 2) }}</x-ltr></td></tr>
                    <tr class="border-t-2 bg-gray-50"><td class="py-3 font-semibold">{{ __('reports.pnl.net_revenue') }}</td><td class="py-3 text-end font-bold text-brand-700"><x-ltr>${{ number_format($net_revenue, 2) }}</x-ltr></td></tr>
                    <tr><td class="py-3 text-gray-500 ps-6">– {{ __('reports.pnl.cogs') }}</td><td class="py-3 text-end text-gray-500"><x-ltr>${{ number_format($cogs, 2) }}</x-ltr></td></tr>
                    <tr class="border-t-2 bg-gray-50"><td class="py-3 font-bold">{{ __('reports.pnl.gross_profit') }}</td><td class="py-3 text-end font-bold {{ $gross_profit > 0 ? 'text-success' : 'text-danger' }}"><x-ltr>${{ number_format($gross_profit, 2) }}</x-ltr></td></tr>
                    <tr><td class="py-3">{{ __('reports.pnl.margin') }}</td><td class="py-3 text-end"><x-ltr>{{ number_format($margin_pct, 1) }}%</x-ltr></td></tr>
                    <tr><td class="py-3 text-gray-500 ps-6">– {{ __('reports.pnl.fixed_expenses') }}</td><td class="py-3 text-end text-gray-500"><x-ltr>${{ number_format($fixed_expenses, 2) }}</x-ltr></td></tr>
                    <tr><td class="py-3 text-gray-500 ps-6">– {{ __('reports.pnl.running_expenses') }}</td><td class="py-3 text-end text-gray-500"><x-ltr>${{ number_format($running_expenses, 2) }}</x-ltr></td></tr>
                    <tr class="border-t-2 bg-gray-50"><td class="py-3 font-bold">{{ __('reports.pnl.net_profit') }}</td><td class="py-3 text-end font-bold {{ $net_profit > 0 ? 'text-success' : 'text-danger' }}"><x-ltr>{{ $net_profit < 0 ? '-' : '' }}${{ number_format(abs($net_profit), 2) }}</x-ltr></td></tr>
                    <tr><td class="py-3">{{ __('reports.pnl.net_margin') }}</td><td class="py-3 text-end"><x-ltr>{{ number_format($net_margin_pct, 1) }}%</x-ltr></td></tr>
                    <tr><td class="py-3">{{ __('reports.cols.transactions') }}</td><td class="py-3 text-end"><x-ltr>{{ $txn_count }}</x-ltr></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
