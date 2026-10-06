<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('Bulk Product Upload') }}</h2>
            <a href="{{ route('products.index') }}" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">{{ __('products.import.back') }}</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="bg-white rounded-lg shadow-card p-6 space-y-4">
            <div>
                <h3 class="font-semibold text-sm">{{ __('products.import.step1') }}</h3>
                {{-- Column names are the importer's machine keys: they stay English in every language. --}}
                <p class="text-xs text-gray-500 mt-1">{{ __('products.import.intro') }}</p>
                <table class="mt-3 text-xs border border-gray-200 rounded">
                    <tbody class="divide-y">
                        @foreach (['name', 'category', 'price_usd', 'price_lbp', 'barcode'] as $col)
                            <tr>
                                <td class="p-2 font-mono" dir="ltr"><code>{{ $col }}</code></td>
                                <td class="p-2 text-gray-600">{{ __("products.import.columns.$col") }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="flex gap-2 mt-3 items-center">
                    <a href="{{ route('products.import.template') }}"
                       class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">
                        {{ __('products.import.download_template', ['ext' => \App\Support\Spreadsheet::extension()]) }}
                    </a>
                    <span class="text-xs text-gray-500">{{ __('products.import.fill_it') }}</span>
                </div>
            </div>

            <form method="POST" action="{{ route('products.import.preview') }}" enctype="multipart/form-data"
                  class="border-t pt-4 space-y-3">
                @csrf
                <h3 class="font-semibold text-sm">{{ __('products.import.step2') }}</h3>
                <input type="file" name="file" accept=".csv,.xlsx,.xls" required
                       class="block w-full text-sm border border-gray-300 rounded p-2" />
                <p class="text-xs text-gray-500">{{ __('products.import.nothing_saved') }}</p>
                <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">{{ __('products.import.preview') }}</button>
            </form>
        </div>

        @isset($report)
            @if ($report)
                <div class="bg-white rounded-lg shadow-card p-6 space-y-4">
                    <h3 class="font-semibold text-sm">{{ __('products.import.step3') }} &mdash; <x-ltr>{{ $filename }}</x-ltr></h3>

                    <div class="grid grid-cols-3 gap-3 text-center">
                        <div class="border rounded p-3">
                            <div class="text-2xl font-semibold text-green-700"><x-ltr>{{ count($report['new']) }}</x-ltr></div>
                            <div class="text-xs text-gray-500">{{ __('products.import.to_create') }}</div>
                        </div>
                        <div class="border rounded p-3">
                            <div class="text-2xl font-semibold text-amber-600"><x-ltr>{{ count($report['duplicates']) }}</x-ltr></div>
                            <div class="text-xs text-gray-500">{{ __('products.import.duplicates_skipped') }}</div>
                        </div>
                        <div class="border rounded p-3">
                            <div class="text-2xl font-semibold {{ $report['errors'] ? 'text-red-600' : 'text-gray-400' }}"><x-ltr>{{ count($report['errors']) }}</x-ltr></div>
                            <div class="text-xs text-gray-500">{{ __('products.import.errors') }}</div>
                        </div>
                    </div>

                    @if ($report['errors'])
                        <div>
                            <h4 class="text-xs uppercase text-gray-500 mb-1">{{ __('products.import.errors_heading') }}</h4>
                            <table class="min-w-full text-sm">
                                <tbody class="divide-y">
                                @foreach ($report['errors'] as $e)
                                    <tr>
                                        <td class="py-2 w-20 text-gray-500">{{ __('products.import.row_n', ['n' => $e['row']]) }}</td>
                                        <td class="py-2 text-red-700">{{ $e['reason'] }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($report['duplicates'])
                        <div>
                            <h4 class="text-xs uppercase text-gray-500 mb-1">{{ __('products.import.duplicates_heading') }}</h4>
                            <table class="min-w-full text-sm">
                                <tbody class="divide-y">
                                @foreach ($report['duplicates'] as $d)
                                    <tr>
                                        <td class="py-2 w-20 text-gray-500">{{ __('products.import.row_n', ['n' => $d['row']]) }}</td>
                                        <td class="py-2 font-mono text-xs"><x-ltr>{{ $d['barcode'] }}</x-ltr></td>
                                        <td class="py-2 text-gray-600">{{ __('products.import.already_used_by', ['name' => $d['existing']]) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($report['new'])
                        <div>
                            <h4 class="text-xs uppercase text-gray-500 mb-1">{{ __('products.import.will_create') }}</h4>
                            <table class="min-w-full text-sm">
                                <thead class="text-xs uppercase text-gray-500 border-b">
                                    <tr><th class="text-left py-2">{{ __('products.import.row') }}</th><th class="text-left">{{ __('common.name') }}</th><th class="text-left">{{ __('products.import.barcode') }}</th><th class="text-right">{{ __('products.price_usd') }}</th><th class="text-right">{{ __('reports.cols.stock') }}</th></tr>
                                </thead>
                                <tbody class="divide-y">
                                @foreach (array_slice($report['new'], 0, 50) as $n)
                                    <tr>
                                        <td class="py-2 text-gray-500"><x-ltr>{{ $n['row'] }}</x-ltr></td>
                                        <td class="py-2" dir="auto">{{ $n['data']['name'] }}</td>
                                        <td class="py-2 font-mono text-xs">@isset($n['data']['barcode'])<x-ltr>{{ $n['data']['barcode'] }}</x-ltr>@else{{ __('products.import.generated') }}@endisset</td>
                                        <td class="py-2 text-right"><x-ltr>{{ number_format((float) $n['data']['price_usd'], 2) }}</x-ltr></td>
                                        <td class="py-2 text-right"><x-ltr>{{ $n['data']['stock_qty'] ?? 0 }}</x-ltr></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            @if (count($report['new']) > 50)
                                <p class="text-xs text-gray-500 mt-2">{{ __('products.import.showing_first', ['shown' => 50, 'total' => count($report['new'])]) }}</p>
                            @endif
                        </div>
                    @endif

                    @if ($report['errors'])
                        <p class="text-sm text-red-700 border-t pt-4">{{ __('products.import.nothing_imported') }}</p>
                    @elseif ($report['new'])
                        <form method="POST" action="{{ route('products.import.store') }}" class="border-t pt-4"
                              onsubmit="return confirm({{ Js::from(__('products.import.confirm_create', ['count' => count($report['new'])])) }})">
                            @csrf
                            <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">
                                {{ __('products.import.confirm_btn', ['count' => count($report['new'])]) }}
                            </button>
                        </form>
                    @else
                        <p class="text-sm text-gray-500 border-t pt-4">{{ __('products.import.nothing_new') }}</p>
                    @endif
                </div>
            @endif
        @endisset
    </div>
</x-app-layout>
