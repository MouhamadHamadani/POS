<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $title }}</h2></x-slot>

    @php $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') ?: '0'; @endphp

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

        <div class="flex gap-2 text-sm">
            @foreach (['on_date', 'movement'] as $m)
                <a href="{{ route('reports.inventory-by-date', ['mode' => $m, 'category_id' => $category_id]) }}"
                   class="px-3 py-1.5 rounded {{ $mode === $m ? 'bg-brand-700 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' }}">{{ __("reports.inv.mode_$m") }}</a>
            @endforeach
        </div>

        <form method="GET" class="bg-white rounded-lg shadow-card p-4 flex flex-wrap gap-3 items-end text-sm">
            <input type="hidden" name="mode" value="{{ $mode }}" />
            @if ($mode === 'on_date')
                <div>
                    <label class="block text-xs text-gray-500">{{ __('reports.inv.date') }}</label>
                    <input type="date" name="date" dir="ltr" value="{{ $date->format('Y-m-d') }}" class="border-gray-300 rounded text-sm" />
                </div>
            @else
                <div>
                    <label class="block text-xs text-gray-500">{{ __('reports.from') }}</label>
                    <input type="date" name="from" dir="ltr" value="{{ $from->format('Y-m-d') }}" class="border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">{{ __('reports.to') }}</label>
                    <input type="date" name="to" dir="ltr" value="{{ $to->format('Y-m-d') }}" class="border-gray-300 rounded text-sm" />
                </div>
            @endif
            <div>
                <label class="block text-xs text-gray-500">{{ __('products.category') }}</label>
                <select name="category_id" class="border-gray-300 rounded text-sm">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}" @selected($category_id === $c->id)>{{ $c->localName() }}</option>
                    @endforeach
                </select>
            </div>
            @if ($mode === 'movement')
                <label class="flex items-center gap-2 pb-1.5">
                    <input type="checkbox" name="only_moved" value="1" @checked($only_moved) />
                    <span>{{ __('reports.inv.only_moved') }}</span>
                </label>
            @endif
            <button class="px-3 py-1.5 bg-gray-800 text-white rounded text-xs">{{ __('reports.apply') }}</button>
            @error('demo')
                <div class="w-full p-2 bg-amber-50 text-amber-800 rounded text-xs">{{ $message }}</div>
            @enderror
            @if ($errors->any() && ! $errors->has('demo'))
                <div class="w-full p-2 bg-red-50 text-red-700 rounded text-xs">{{ $errors->first() }}</div>
            @endif
            @if (\App\Support\Demo::enabled())
                <span class="px-3 py-1.5 bg-gray-200 text-gray-500 rounded text-xs" title="{{ __('reports.exports_disabled_title') }}">{{ __('reports.export_disabled') }}</span>
            @else
                <a href="{{ url()->current() }}?{{ http_build_query(array_merge(request()->query(), ['format' => 'pdf'])) }}"
                   class="px-3 py-1.5 bg-red-600 text-white rounded text-xs">{{ __('reports.export_pdf') }}</a>
                <a href="{{ url()->current() }}?{{ http_build_query(array_merge(request()->query(), ['format' => 'xlsx'])) }}"
                   class="px-3 py-1.5 bg-green-600 text-white rounded text-xs">{{ __('reports.export_format', ['format' => strtoupper(\App\Support\Spreadsheet::extension())]) }}</a>
            @endif
            <a href="{{ route('reports.index') }}" class="text-xs text-gray-500 hover:underline ms-auto"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('reports.all_reports') }}</a>
        </form>

        @if ($mode === 'on_date')
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="bg-white rounded-lg shadow-card p-4">
                    <div class="text-xs text-gray-500">{{ __('reports.value_cost') }}</div>
                    <div class="text-2xl font-bold text-brand-700 mt-1"><x-ltr>${{ number_format((float) $totals['value_cost'], 2) }}</x-ltr></div>
                </div>
                <div class="bg-white rounded-lg shadow-card p-4">
                    <div class="text-xs text-gray-500">{{ __('reports.value_retail') }}</div>
                    <div class="text-2xl font-bold text-accent mt-1"><x-ltr>${{ number_format((float) $totals['value_retail'], 2) }}</x-ltr></div>
                </div>
                <div class="bg-white rounded-lg shadow-card p-4">
                    <div class="text-xs text-gray-500">{{ __('reports.inv.stock_on', ['date' => $date->format('Y-m-d')]) }}</div>
                    <div class="text-xs text-gray-500 mt-2">{{ __('reports.tracked_products', ['count' => $totals['count']]) }}</div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-card overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="p-3 text-start">{{ __('dashboard.product') }}</th>
                            <th class="p-3 text-start">SKU</th>
                            <th class="p-3 text-end">{{ __('reports.cols.qty_on_date') }}</th>
                            <th class="p-3 text-end">{{ __('purchases.cost') }}</th>
                            <th class="p-3 text-end">{{ __('reports.cols.cost_value') }}</th>
                            <th class="p-3 text-end">{{ __('reports.cols.retail_value') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                    @forelse ($rows as $r)
                        <tr>
                            <td class="p-3">{{ $r['local_name'] }}</td>
                            <td class="p-3 text-xs text-gray-500"><x-ltr>{{ $r['sku'] }}</x-ltr></td>
                            <td class="p-3 text-end"><x-ltr>{{ $qty($r['qty']) }}</x-ltr> {{ \App\Support\EnumLabel::get('unit', $r['unit']) }}</td>
                            <td class="p-3 text-end text-xs"><x-ltr>${{ number_format($r['cost_usd'], 4) }}</x-ltr></td>
                            <td class="p-3 text-end"><x-ltr>${{ number_format($r['value_cost'], 2) }}</x-ltr></td>
                            <td class="p-3 text-end"><x-ltr>${{ number_format($r['value_retail'], 2) }}</x-ltr></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-10 text-center text-gray-500">{{ __('reports.no_products_match') }}</td></tr>
                    @endforelse
                    </tbody>
                    @if (count($rows))
                        <tfoot class="bg-gray-50 font-semibold">
                            <tr>
                                <td class="p-3" colspan="4">{{ __('reports.cols.totals') }}</td>
                                <td class="p-3 text-end"><x-ltr>${{ number_format($totals['value_cost'], 2) }}</x-ltr></td>
                                <td class="p-3 text-end"><x-ltr>${{ number_format($totals['value_retail'], 2) }}</x-ltr></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <p class="text-xs text-gray-500">{{ __('reports.inv.note_cost') }}</p>
        @else
            <div class="bg-white rounded-lg shadow-card overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="p-3 text-start">{{ __('dashboard.product') }}</th>
                            <th class="p-3 text-start">SKU</th>
                            <th class="p-3 text-end">{{ __('reports.cols.opening_qty') }}</th>
                            <th class="p-3 text-end">{{ __('reports.cols.total_in') }}</th>
                            <th class="p-3 text-end">{{ __('reports.cols.total_out') }}</th>
                            <th class="p-3 text-end">{{ __('reports.cols.closing_qty') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                    @forelse ($rows as $r)
                        <tr>
                            <td class="p-3">{{ $r['local_name'] }}</td>
                            <td class="p-3 text-xs text-gray-500"><x-ltr>{{ $r['sku'] }}</x-ltr></td>
                            <td class="p-3 text-end"><x-ltr>{{ $qty($r['opening']) }}</x-ltr></td>
                            <td class="p-3 text-end text-green-700"><x-ltr>{{ $qty($r['qty_in']) }}</x-ltr></td>
                            <td class="p-3 text-end text-red-700"><x-ltr>{{ $qty($r['qty_out']) }}</x-ltr></td>
                            <td class="p-3 text-end font-medium"><x-ltr>{{ $qty($r['closing']) }}</x-ltr> {{ \App\Support\EnumLabel::get('unit', $r['unit']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-10 text-center text-gray-500">{{ __('reports.no_products_match') }}</td></tr>
                    @endforelse
                    </tbody>
                    @if (count($rows))
                        <tfoot class="bg-gray-50 font-semibold">
                            <tr>
                                <td class="p-3" colspan="2">{{ __('reports.cols.totals') }}</td>
                                <td class="p-3 text-end"><x-ltr>{{ $qty($totals['opening']) }}</x-ltr></td>
                                <td class="p-3 text-end"><x-ltr>{{ $qty($totals['qty_in']) }}</x-ltr></td>
                                <td class="p-3 text-end"><x-ltr>{{ $qty($totals['qty_out']) }}</x-ltr></td>
                                <td class="p-3 text-end"><x-ltr>{{ $qty($totals['closing']) }}</x-ltr></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        @endif

        <p class="text-xs text-gray-500">{{ __('reports.inv.note_history') }}</p>
    </div>
</x-app-layout>
