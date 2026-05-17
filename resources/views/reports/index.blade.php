<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Reports') }}</h2></x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <p class="text-sm text-gray-600">Pick a report. All reports support PDF and XLSX export from inside.</p>

        @php
            $reports = [
                ['Daily Sales Summary', 'reports.daily-sales', 'Sales totals per day with discount and VAT breakdown.', '📈'],
                ['Sales by Product', 'reports.sales-by-product', 'Top-sellers by units, revenue, profit, and margin %.', '🏆'],
                ['Inventory Stock Levels', 'reports.stock-levels', 'Current stock with cost / retail valuation totals.', '📦'],
                ['Profit & Loss', 'reports.pnl', 'Gross revenue, COGS, discounts, tax, gross profit, margin.', '💵'],
            ];
        @endphp

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($reports as [$label, $route, $desc, $icon])
                <a href="{{ route($route) }}" class="bg-white rounded-lg shadow-card p-5 hover:shadow-pop hover:border-brand-300 border border-transparent transition">
                    <div class="text-2xl mb-2">{{ $icon }}</div>
                    <div class="font-semibold text-brand-700">{{ $label }}</div>
                    <p class="text-xs text-gray-500 mt-1">{{ $desc }}</p>
                </a>
            @endforeach
        </div>
    </div>
</x-app-layout>
