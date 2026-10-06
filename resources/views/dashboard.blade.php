@php
    use App\Models\Sale;
    use App\Models\Product;

    $today = now()->startOfDay();
    $sales = Sale::where('status', Sale::STATUS_COMPLETED)->whereDate('created_at', $today)->get();
    $revenue = (float) $sales->sum('total_usd');
    $txnCount = $sales->count();
    $itemsSold = (float) \App\Models\SaleItem::whereIn('sale_id', $sales->pluck('id'))->sum('qty');
    $lowStock = Product::where('is_active', true)->where('track_stock', true)
        ->whereColumn('stock_qty', '<=', 'min_stock')->count();
    $outOfStock = Product::where('is_active', true)->where('track_stock', true)
        ->where('stock_qty', '<=', 0)->count();
    $productsTotal = Product::where('is_active', true)->count();
@endphp

<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('nav.dashboard') }}</h2></x-slot>

    <div class="py-6 px-4 sm:px-6 lg:px-8 space-y-4">

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('dashboard.todays_revenue') }}</div>
                <div class="text-2xl font-bold text-brand-700 mt-1"><x-ltr>${{ number_format($revenue, 2) }}</x-ltr></div>
                <div class="text-xs text-gray-500 mt-1">{{ trans_choice('dashboard.transactions', $txnCount) }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('dashboard.items_sold_today') }}</div>
                <div class="text-2xl font-bold text-accent mt-1"><x-ltr>{{ rtrim(rtrim(number_format($itemsSold, 2, '.', ''), '0'), '.') }}</x-ltr></div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('dashboard.active_products') }}</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">{{ $productsTotal }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('dashboard.low_stock_alerts') }}</div>
                <div class="text-2xl font-bold {{ $lowStock > 0 ? 'text-warning' : 'text-success' }} mt-1">{{ $lowStock }}</div>
                <div class="text-xs text-danger mt-1">{{ __('dashboard.out_of_stock', ['count' => $outOfStock]) }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-white rounded-lg shadow-card p-5">
                <h3 class="font-semibold text-sm mb-3">{{ __('dashboard.recent_sales') }}</h3>
                @php $recent = Sale::with('customer:id,name', 'user:id,name')->latest()->limit(8)->get(); @endphp
                <table class="w-full text-xs">
                    <thead class="text-gray-500 text-xs uppercase">
                        <tr class="border-b"><th class="text-left py-2">{{ __('dashboard.receipt') }}</th><th class="text-left">{{ __('dashboard.cashier') }}</th><th class="text-right">{{ __('dashboard.total') }}</th><th class="text-right">{{ __('dashboard.when') }}</th></tr>
                    </thead>
                    <tbody class="divide-y">
                    @forelse ($recent as $s)
                        <tr>
                            <td class="py-2"><x-ltr>{{ $s->receipt_number }}</x-ltr></td>
                            <td>{{ $s->user?->name ?? '—' }}</td>
                            <td class="text-right font-medium"><x-ltr>${{ number_format((float) $s->total_usd, 2) }}</x-ltr></td>
                            <td class="text-right text-gray-500">{{ $s->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('dashboard.no_sales') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-white rounded-lg shadow-card p-5">
                <h3 class="font-semibold text-sm mb-3">{{ __('dashboard.low_stock_products') }}</h3>
                @php $low = Product::where('is_active', true)->where('track_stock', true)
                        ->whereColumn('stock_qty', '<=', 'min_stock')->limit(8)->get(); @endphp
                <table class="w-full text-xs">
                    <thead class="text-gray-500 text-xs uppercase">
                        <tr class="border-b"><th class="text-left py-2">{{ __('dashboard.product') }}</th><th class="text-right">{{ __('dashboard.in_stock') }}</th><th class="text-right">{{ __('dashboard.min') }}</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y">
                    @forelse ($low as $p)
                        <tr>
                            <td class="py-2">{{ $p->localName() }}</td>
                            <td class="text-right {{ $p->stock_qty <= 0 ? 'text-danger font-bold' : 'text-warning font-medium' }}"><x-ltr>{{ rtrim(rtrim(number_format((float) $p->stock_qty, 4, '.', ''), '0'), '.') }}</x-ltr> {{ \App\Support\EnumLabel::get('unit', $p->unit) }}</td>
                            <td class="text-right text-gray-500"><x-ltr>{{ rtrim(rtrim(number_format((float) $p->min_stock, 4, '.', ''), '0'), '.') }}</x-ltr></td>
                            <td class="text-right"><a href="{{ route('products.edit', $p) }}" class="text-accent text-xs">{{ __('dashboard.reorder') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('dashboard.all_healthy') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
