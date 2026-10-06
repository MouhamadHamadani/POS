<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('products.title') }}</h2>
            <div class="flex gap-2">
                <a href="{{ route('categories.index') }}" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">{{ __('Categories') }}</a>
                {{-- Vendor/owner only, like the Backup and Permissions tabs in Settings. The
                     route is gated too (role:super_admin) — this just hides a dead link. --}}
                @if (auth()->user()->isSuperAdmin())
                    <a href="{{ route('products.trashed') }}" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">{{ __('products.trashed.nav') }}</a>
                @endif
                <a href="{{ route('products.create') }}" class="px-3 py-2 text-sm bg-brand-600 text-white rounded hover:bg-brand-700">{{ __('products.new_product') }}</a>
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
                    <label class="block text-xs text-gray-500">{{ __('common.search') }}</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('products.search_placeholder') }}"
                           dir="auto" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">{{ __('products.category') }}</label>
                    <select name="category" class="w-full border-gray-300 rounded text-sm">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->localName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">{{ __('common.status') }}</label>
                    <select name="status" class="w-full border-gray-300 rounded text-sm">
                        <option value="active" @selected(request('status', 'active') === 'active')>{{ __('common.active') }}</option>
                        <option value="inactive" @selected(request('status') === 'inactive')>{{ __('common.inactive') }}</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-xs text-gray-500 flex items-center gap-1">
                        <input type="checkbox" name="low_stock" value="1" @checked(request('low_stock'))>
                        {{ __('products.low_stock') }}
                    </label>
                    <button class="ms-auto px-3 py-1.5 bg-gray-800 text-white rounded text-xs">{{ __('common.filter') }}</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-start">{{ __('products.image') }}</th>
                        <th class="p-3 text-start">{{ __('common.name') }}</th>
                        <th class="p-3 text-start">{{ __('products.sku_barcode') }}</th>
                        <th class="p-3 text-start">{{ __('products.category') }}</th>
                        <th class="p-3 text-end">{{ __('products.price_usd') }}</th>
                        <th class="p-3 text-end">{{ __('reports.cols.stock') }}</th>
                        <th class="p-3 text-center">{{ __('common.status') }}</th>
                        <th class="p-3 text-end">{{ __('common.actions') }}</th>
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
                                <div class="font-medium text-gray-900">{{ $p->localName() }}</div>
                                @if ($p->name_ar && $p->name_ar !== $p->name)
                                    <div class="text-xs text-gray-500" dir="{{ app()->getLocale() === 'ar' ? 'ltr' : 'rtl' }}">{{ app()->getLocale() === 'ar' ? $p->name : $p->name_ar }}</div>
                                @endif
                            </td>
                            <td class="p-3 text-xs text-gray-600">
                                <div>@if ($p->sku)<x-ltr>{{ $p->sku }}</x-ltr>@else — @endif</div>
                                <div class="text-gray-400">@if ($p->barcode)<x-ltr>{{ $p->barcode }}</x-ltr>@else — @endif</div>
                            </td>
                            <td class="p-3 text-gray-700">{{ $p->category?->localName() ?? '—' }}</td>
                            <td class="p-3 text-end font-medium"><x-ltr>${{ number_format((float) $p->price_usd, 2) }}</x-ltr></td>
                            <td class="p-3 text-end">
                                @if ($p->track_stock)
                                    <span @class([
                                        'text-red-600 font-bold' => $p->stock_qty <= 0,
                                        'text-orange-600 font-semibold' => $p->stock_qty > 0 && $p->stock_qty <= $p->min_stock,
                                    ])>
                                        <x-ltr>{{ rtrim(rtrim(number_format((float) $p->stock_qty, 4, '.', ''), '0'), '.') }}</x-ltr>
                                    </span>
                                    <span class="text-gray-400 text-xs">{{ \App\Support\EnumLabel::get('unit', $p->unit) }}</span>
                                @else
                                    <span class="text-xs text-gray-400">∞</span>
                                @endif
                            </td>
                            <td class="p-3 text-center">
                                @if ($p->is_active)
                                    <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded">{{ __('common.active') }}</span>
                                @else
                                    <span class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded">{{ __('common.inactive') }}</span>
                                @endif
                            </td>
                            <td class="p-3 text-end">
                                <a href="{{ route('products.edit', $p) }}" class="text-brand-600 hover:underline text-xs">{{ __('common.edit') }}</a>
                                <form method="POST" action="{{ route('products.destroy', $p) }}" class="inline ms-2"
                                      onsubmit="return confirm({{ Js::from(__('products.confirm_delete', ['name' => $p->localName()])) }})">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:underline text-xs">{{ __('common.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-10 text-center text-gray-500">
                            {{ __('products.none_match') }} <a href="{{ route('products.create') }}" class="text-brand-600">{{ __('products.create_first') }}</a>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $products->links() }}</div>
    </div>
</x-app-layout>
