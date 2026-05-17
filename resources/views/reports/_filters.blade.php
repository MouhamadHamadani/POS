<form method="GET" class="bg-white rounded-lg shadow-card p-4 flex flex-wrap gap-3 items-end text-sm">
    @if (isset($from))
        <div>
            <label class="block text-xs text-gray-500">From</label>
            <input type="date" name="from" value="{{ $from->format('Y-m-d') }}" class="border-gray-300 rounded text-sm" />
        </div>
        <div>
            <label class="block text-xs text-gray-500">To</label>
            <input type="date" name="to" value="{{ $to->format('Y-m-d') }}" class="border-gray-300 rounded text-sm" />
        </div>
    @endif
    {{ $slot ?? '' }}
    <button class="px-3 py-1.5 bg-gray-800 text-white rounded text-xs">Apply</button>
    <a href="{{ url()->current() }}?{{ http_build_query(array_merge(request()->query(), ['format' => 'pdf'])) }}"
       class="px-3 py-1.5 bg-red-600 text-white rounded text-xs">Export PDF</a>
    <a href="{{ url()->current() }}?{{ http_build_query(array_merge(request()->query(), ['format' => 'xlsx'])) }}"
       class="px-3 py-1.5 bg-green-600 text-white rounded text-xs">Export XLSX</a>
    <a href="{{ route('reports.index') }}" class="text-xs text-gray-500 hover:underline ml-auto">← All reports</a>
</form>
