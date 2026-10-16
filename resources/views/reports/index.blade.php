<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Reports') }}</h2></x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <p class="text-sm text-gray-600">
            {{ __('reports.pick') }}
            @if (\App\Support\Demo::enabled())
                {{ __('reports.demo_no_exports') }}
            @else
                {{ __('reports.exports_note', ['format' => strtoupper(\App\Support\Spreadsheet::extension())]) }}
            @endif
        </p>

        @php
            // The fourth column is the tier feature each report needs; null ships
            // on every tier. Hiding a card is cosmetic — the routes are gated by
            // `feature:` middleware regardless.
            $reports = array_filter([
                ['daily_sales', 'reports.daily-sales', '📈', null],
                ['sales_by_product', 'reports.sales-by-product', '🏆', 'reports.sales_by_product'],
                ['stock_levels', 'reports.stock-levels', '📦', 'reports.stock_levels'],
                ['inventory_by_date', 'reports.inventory-by-date', '🗓️', 'reports.stock_levels'],
                ['pnl', 'reports.pnl', '💵', 'reports.profit_loss'],
            ], fn ($r) => $r[3] === null || \App\Support\License::allows($r[3]));
        @endphp

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($reports as [$key, $route, $icon, $feature])
                <a href="{{ route($route) }}" class="bg-white rounded-lg shadow-card p-5 hover:shadow-pop hover:border-brand-300 border border-transparent transition">
                    <div class="text-2xl mb-2">{{ $icon }}</div>
                    <div class="font-semibold text-brand-700">{{ __("reports.titles.$key") }}</div>
                    <p class="text-xs text-gray-500 mt-1">{{ __("reports.descriptions.$key") }}</p>
                </a>
            @endforeach
        </div>
    </div>
</x-app-layout>
