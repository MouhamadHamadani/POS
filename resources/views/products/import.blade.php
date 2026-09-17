<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('Bulk Product Upload') }}</h2>
            <a href="{{ route('products.index') }}" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">Back to Products</a>
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
                <h3 class="font-semibold text-sm">1. Start from the template</h3>
                <p class="text-xs text-gray-500 mt-1">
                    <code>name</code>, <code>category</code> and <code>price_usd</code> are required on every row.
                    Category and tax are matched by name and must already exist. Prices are in USD —
                    leave <code>price_lbp</code> blank unless this product is priced in LBP directly.
                    A blank <code>barcode</code> gets one generated.
                </p>
                <div class="flex gap-2 mt-3 items-center">
                    <a href="{{ route('products.import.template') }}"
                       class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">
                        Download template (.{{ \App\Support\Spreadsheet::extension() }})
                    </a>
                    <span class="text-xs text-gray-500">Fill it in, then upload it below. .csv, .xlsx and .xls uploads are all accepted.</span>
                </div>
            </div>

            <form method="POST" action="{{ route('products.import.preview') }}" enctype="multipart/form-data"
                  class="border-t pt-4 space-y-3">
                @csrf
                <h3 class="font-semibold text-sm">2. Upload and preview</h3>
                <input type="file" name="file" accept=".csv,.xlsx,.xls" required
                       class="block w-full text-sm border border-gray-300 rounded p-2" />
                <p class="text-xs text-gray-500">Nothing is saved until you confirm the preview.</p>
                <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">Preview</button>
            </form>
        </div>

        @isset($report)
            @if ($report)
                <div class="bg-white rounded-lg shadow-card p-6 space-y-4">
                    <h3 class="font-semibold text-sm">3. Review &mdash; {{ $filename }}</h3>

                    <div class="grid grid-cols-3 gap-3 text-center">
                        <div class="border rounded p-3">
                            <div class="text-2xl font-semibold text-green-700">{{ count($report['new']) }}</div>
                            <div class="text-xs text-gray-500">to create</div>
                        </div>
                        <div class="border rounded p-3">
                            <div class="text-2xl font-semibold text-amber-600">{{ count($report['duplicates']) }}</div>
                            <div class="text-xs text-gray-500">duplicate barcode, skipped</div>
                        </div>
                        <div class="border rounded p-3">
                            <div class="text-2xl font-semibold {{ $report['errors'] ? 'text-red-600' : 'text-gray-400' }}">{{ count($report['errors']) }}</div>
                            <div class="text-xs text-gray-500">errors</div>
                        </div>
                    </div>

                    @if ($report['errors'])
                        <div>
                            <h4 class="text-xs uppercase text-gray-500 mb-1">Errors &mdash; fix these and upload again</h4>
                            <table class="min-w-full text-sm">
                                <tbody class="divide-y">
                                @foreach ($report['errors'] as $e)
                                    <tr>
                                        <td class="py-2 w-20 text-gray-500">Row {{ $e['row'] }}</td>
                                        <td class="py-2 text-red-700">{{ $e['reason'] }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($report['duplicates'])
                        <div>
                            <h4 class="text-xs uppercase text-gray-500 mb-1">Duplicate barcodes &mdash; these rows are skipped</h4>
                            <table class="min-w-full text-sm">
                                <tbody class="divide-y">
                                @foreach ($report['duplicates'] as $d)
                                    <tr>
                                        <td class="py-2 w-20 text-gray-500">Row {{ $d['row'] }}</td>
                                        <td class="py-2 font-mono text-xs">{{ $d['barcode'] }}</td>
                                        <td class="py-2 text-gray-600">already used by {{ $d['existing'] }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($report['new'])
                        <div>
                            <h4 class="text-xs uppercase text-gray-500 mb-1">Will be created</h4>
                            <table class="min-w-full text-sm">
                                <thead class="text-xs uppercase text-gray-500 border-b">
                                    <tr><th class="text-left py-2">Row</th><th class="text-left">Name</th><th class="text-left">Barcode</th><th class="text-right">Price (USD)</th><th class="text-right">Stock</th></tr>
                                </thead>
                                <tbody class="divide-y">
                                @foreach (array_slice($report['new'], 0, 50) as $n)
                                    <tr>
                                        <td class="py-2 text-gray-500">{{ $n['row'] }}</td>
                                        <td class="py-2">{{ $n['data']['name'] }}</td>
                                        <td class="py-2 font-mono text-xs">{{ $n['data']['barcode'] ?? '(generated)' }}</td>
                                        <td class="py-2 text-right">{{ number_format((float) $n['data']['price_usd'], 2) }}</td>
                                        <td class="py-2 text-right">{{ $n['data']['stock_qty'] ?? 0 }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            @if (count($report['new']) > 50)
                                <p class="text-xs text-gray-500 mt-2">Showing the first 50 of {{ count($report['new']) }}.</p>
                            @endif
                        </div>
                    @endif

                    @if ($report['errors'])
                        <p class="text-sm text-red-700 border-t pt-4">
                            Nothing will be imported while any row has an error &mdash; a half-imported
                            catalogue is worse than none. Fix the rows above and upload again.
                        </p>
                    @elseif ($report['new'])
                        <form method="POST" action="{{ route('products.import.store') }}" class="border-t pt-4"
                              onsubmit="return confirm('Create {{ count($report['new']) }} product(s)?')">
                            @csrf
                            <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">
                                Confirm &mdash; create {{ count($report['new']) }} product(s)
                            </button>
                        </form>
                    @else
                        <p class="text-sm text-gray-500 border-t pt-4">Nothing new to import in this file.</p>
                    @endif
                </div>
            @endif
        @endisset
    </div>
</x-app-layout>
