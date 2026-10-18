<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('stock.title') }}</h2>
            <a href="{{ route('products.index') }}" class="text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('categories.back_to_products') }}</a>
        </div>
    </x-slot>

    @php $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') ?: '0'; @endphp

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

        @error('demo')
            <div class="p-2 bg-amber-50 text-amber-800 rounded text-xs">{{ $message }}</div>
        @enderror
        @if ($errors->any() && ! $errors->has('demo'))
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="bg-white rounded-lg shadow-sm p-4">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-2 items-end text-sm">
                <div>
                    <label class="block text-xs text-gray-500">{{ __('reports.from') }}</label>
                    <input type="date" name="from" dir="ltr" value="{{ $f['from']->format('Y-m-d') }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">{{ __('reports.to') }}</label>
                    <input type="date" name="to" dir="ltr" value="{{ $f['to']->format('Y-m-d') }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs text-gray-500">{{ __('stock.cols.product') }}</label>
                    <input type="text" name="product" dir="auto" value="{{ $f['product'] }}" placeholder="{{ __('products.search_placeholder') }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">{{ __('products.category') }}</label>
                    <select name="category_id" class="w-full border-gray-300 rounded text-sm">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}" @selected($f['category_id'] === $c->id)>{{ $c->localName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">{{ __('stock.direction') }}</label>
                    <select name="direction" class="w-full border-gray-300 rounded text-sm">
                        @foreach (['all', 'in', 'out'] as $d)
                            <option value="{{ $d }}" @selected($f['direction'] === $d)>{{ __("stock.directions.$d") }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">{{ __('stock.cols.type') }}</label>
                    <select name="type" class="w-full border-gray-300 rounded text-sm">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach (\App\Models\InventoryAdjustment::TYPES as $t)
                            <option value="{{ $t }}" @selected($f['type'] === $t)>{{ \App\Support\EnumLabel::get('stock_movement_type', $t) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-5 flex flex-wrap items-center gap-2">
                    @if ($f['product_id'])
                        <input type="hidden" name="product_id" value="{{ $f['product_id'] }}" />
                        <span class="text-xs px-2 py-1 rounded bg-brand-50 text-brand-700">
                            {{ __('stock.only_product', ['name' => $product?->localName() ?? '#'.$f['product_id']]) }}
                            <a href="{{ route('products.movements', collect(request()->query())->except('product_id')->all()) }}" class="ms-1 text-gray-500" aria-label="{{ __('stock.clear_product') }}">×</a>
                        </span>
                    @endif
                    <button class="px-3 py-1.5 bg-gray-800 text-white rounded text-xs">{{ __('reports.apply') }}</button>
                    <a href="{{ route('products.movements') }}" class="px-3 py-1.5 bg-gray-100 rounded text-xs">{{ __('stock.reset') }}</a>
                    @if (\App\Support\Demo::enabled())
                        <span class="px-3 py-1.5 bg-gray-200 text-gray-500 rounded text-xs" title="{{ __('reports.exports_disabled_title') }}">{{ __('reports.export_disabled') }}</span>
                    @else
                        <a href="{{ route('products.movements.export', request()->query()) }}" class="px-3 py-1.5 bg-green-600 text-white rounded text-xs">{{ __('reports.export_format', ['format' => strtoupper(\App\Support\Spreadsheet::extension())]) }}</a>
                    @endif
                </div>
            </form>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('stock.total_in') }}</div>
                <div class="text-2xl font-bold text-success mt-1"><x-ltr>+{{ $qty($summary['in']) }}</x-ltr></div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('stock.total_out') }}</div>
                <div class="text-2xl font-bold text-danger mt-1"><x-ltr>−{{ $qty($summary['out']) }}</x-ltr></div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">{{ __('stock.net') }}</div>
                <div class="text-2xl font-bold text-brand-700 mt-1"><x-ltr>{{ $summary['net'] > 0 ? '+' : '' }}{{ $qty($summary['net']) }}</x-ltr></div>
            </div>
        </div>

        @include('products._movements-table', ['rows' => $rows, 'showProduct' => true])

        <div>{{ $rows->links() }}</div>
    </div>
</x-app-layout>
