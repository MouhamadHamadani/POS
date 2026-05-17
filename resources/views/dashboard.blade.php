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
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Dashboard') }}</h2></x-slot>

    <div class="py-6 px-4 sm:px-6 lg:px-8 space-y-4">

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">Today's Revenue</div>
                <div class="text-2xl font-bold text-brand-700 mt-1">${{ number_format($revenue, 2) }}</div>
                <div class="text-xs text-gray-500 mt-1">{{ $txnCount }} transaction{{ $txnCount === 1 ? '' : 's' }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">Items Sold Today</div>
                <div class="text-2xl font-bold text-accent mt-1">{{ rtrim(rtrim(number_format($itemsSold, 2, '.', ''), '0'), '.') }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">Active Products</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">{{ $productsTotal }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">Low Stock Alerts</div>
                <div class="text-2xl font-bold {{ $lowStock > 0 ? 'text-warning' : 'text-success' }} mt-1">{{ $lowStock }}</div>
                <div class="text-xs text-danger mt-1">{{ $outOfStock }} out of stock</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-white rounded-lg shadow-card p-5">
                <h3 class="font-semibold text-sm mb-3">Recent Sales</h3>
                @php $recent = Sale::with('customer:id,name', 'user:id,name')->latest()->limit(8)->get(); @endphp
                <table class="w-full text-xs">
                    <thead class="text-gray-500 text-xs uppercase">
                        <tr class="border-b"><th class="text-left py-2">Receipt</th><th class="text-left">Cashier</th><th class="text-right">Total</th><th class="text-right">When</th></tr>
                    </thead>
                    <tbody class="divide-y">
                    @forelse ($recent as $s)
                        <tr>
                            <td class="py-2">{{ $s->receipt_number }}</td>
                            <td>{{ $s->user?->name ?? '—' }}</td>
                            <td class="text-right font-medium">${{ number_format((float) $s->total_usd, 2) }}</td>
                            <td class="text-right text-gray-500">{{ $s->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-400">No sales yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-white rounded-lg shadow-card p-5">
                <h3 class="font-semibold text-sm mb-3">Low Stock Products</h3>
                @php $low = Product::where('is_active', true)->where('track_stock', true)
                        ->whereColumn('stock_qty', '<=', 'min_stock')->limit(8)->get(); @endphp
                <table class="w-full text-xs">
                    <thead class="text-gray-500 text-xs uppercase">
                        <tr class="border-b"><th class="text-left py-2">Product</th><th class="text-right">In Stock</th><th class="text-right">Min</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y">
                    @forelse ($low as $p)
                        <tr>
                            <td class="py-2">{{ $p->name }}</td>
                            <td class="text-right {{ $p->stock_qty <= 0 ? 'text-danger font-bold' : 'text-warning font-medium' }}">{{ rtrim(rtrim(number_format((float) $p->stock_qty, 4, '.', ''), '0'), '.') }} {{ $p->unit }}</td>
                            <td class="text-right text-gray-500">{{ rtrim(rtrim(number_format((float) $p->min_stock, 4, '.', ''), '0'), '.') }}</td>
                            <td class="text-right"><a href="{{ route('products.edit', $p) }}" class="text-accent text-xs">Reorder</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-400">All products at healthy stock levels.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
