<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('Products') }}</h2>
            <div class="flex gap-2">
                <a href="{{ route('categories.index') }}" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">Categories</a>
                <a href="{{ route('products.create') }}" class="px-3 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700">+ New Product</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

        @if (session('success'))
            <div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>
        @endif

        <div class="bg-white rounded-lg shadow-sm p-4">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-2 items-end text-sm">
                <div class="md:col-span-2">
                    <label class="block text-xs text-gray-500">Search</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, SKU, barcode"
                           class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Category</label>
                    <select name="category" class="w-full border-gray-300 rounded text-sm">
                        <option value="">All</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Status</label>
                    <select name="status" class="w-full border-gray-300 rounded text-sm">
                        <option value="active" @selected(request('status', 'active') === 'active')>Active</option>
                        <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-xs text-gray-500 flex items-center gap-1">
                        <input type="checkbox" name="low_stock" value="1" @checked(request('low_stock'))>
                        Low stock
                    </label>
                    <button class="ml-auto px-3 py-1.5 bg-gray-800 text-white rounded text-xs">Filter</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-left">Image</th>
                        <th class="p-3 text-left">Name</th>
                        <th class="p-3 text-left">SKU / Barcode</th>
                        <th class="p-3 text-left">Category</th>
                        <th class="p-3 text-right">Price (USD)</th>
                        <th class="p-3 text-right">Stock</th>
                        <th class="p-3 text-center">Status</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($products as $p)
                        <tr class="hover:bg-gray-50">
                            <td class="p-3">
                                @if ($p->image)
                                    <img src="{{ Storage::url($p->image) }}" alt="" class="w-10 h-10 rounded object-cover">
                                @else
                                    <div class="w-10 h-10 rounded bg-gray-100 flex items-center justify-center text-gray-400 text-xs">—</div>
                                @endif
                            </td>
                            <td class="p-3">
                                <div class="font-medium text-gray-900">{{ $p->name }}</div>
                                @if ($p->name_ar)
                                    <div class="text-xs text-gray-500" dir="rtl">{{ $p->name_ar }}</div>
                                @endif
                            </td>
                            <td class="p-3 text-xs text-gray-600">
                                <div>{{ $p->sku ?? '—' }}</div>
                                <div class="text-gray-400">{{ $p->barcode ?? '—' }}</div>
                            </td>
                            <td class="p-3 text-gray-700">{{ $p->category?->name ?? '—' }}</td>
                            <td class="p-3 text-right font-medium">${{ number_format((float) $p->price_usd, 2) }}</td>
                            <td class="p-3 text-right">
                                @if ($p->track_stock)
                                    <span @class([
                                        'text-red-600 font-bold' => $p->stock_qty <= 0,
                                        'text-orange-600 font-semibold' => $p->stock_qty > 0 && $p->stock_qty <= $p->min_stock,
                                    ])>
                                        {{ rtrim(rtrim(number_format((float) $p->stock_qty, 4, '.', ''), '0'), '.') }}
                                    </span>
                                    <span class="text-gray-400 text-xs">{{ $p->unit }}</span>
                                @else
                                    <span class="text-xs text-gray-400">∞</span>
                                @endif
                            </td>
                            <td class="p-3 text-center">
                                @if ($p->is_active)
                                    <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded">Active</span>
                                @else
                                    <span class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded">Inactive</span>
                                @endif
                            </td>
                            <td class="p-3 text-right">
                                <a href="{{ route('products.edit', $p) }}" class="text-blue-600 hover:underline text-xs">Edit</a>
                                <form method="POST" action="{{ route('products.destroy', $p) }}" class="inline ml-2"
                                      onsubmit="return confirm('Delete {{ $p->name }}?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:underline text-xs">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-10 text-center text-gray-500">
                            No products match. <a href="{{ route('products.create') }}" class="text-blue-600">Create the first one.</a>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $products->links() }}</div>
    </div>
</x-app-layout>
