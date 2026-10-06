@error('demo')
    <div class="w-full p-2 bg-amber-50 text-amber-800 rounded text-xs">{{ $message }}</div>
@enderror

<form method="GET" class="bg-white rounded-lg shadow-card p-4 flex flex-wrap gap-3 items-end text-sm">
    @if (isset($from))
        <div>
            <label class="block text-xs text-gray-500">{{ __('reports.from') }}</label>
            <input type="date" name="from" dir="ltr" value="{{ $from->format('Y-m-d') }}" class="border-gray-300 rounded text-sm" />
        </div>
        <div>
            <label class="block text-xs text-gray-500">{{ __('reports.to') }}</label>
            <input type="date" name="to" dir="ltr" value="{{ $to->format('Y-m-d') }}" class="border-gray-300 rounded text-sm" />
        </div>
    @endif
    {{ $slot ?? '' }}
    <button class="px-3 py-1.5 bg-gray-800 text-white rounded text-xs">{{ __('reports.apply') }}</button>
    @if (\App\Support\Demo::enabled())
        <span class="px-3 py-1.5 bg-gray-200 text-gray-500 rounded text-xs" title="{{ __('reports.exports_disabled_title') }}">
            {{ __('reports.export_disabled') }}
        </span>
    @else
        <a href="{{ url()->current() }}?{{ http_build_query(array_merge(request()->query(), ['format' => 'pdf'])) }}"
           class="px-3 py-1.5 bg-red-600 text-white rounded text-xs">{{ __('reports.export_pdf') }}</a>
        <a href="{{ url()->current() }}?{{ http_build_query(array_merge(request()->query(), ['format' => 'xlsx'])) }}"
           class="px-3 py-1.5 bg-green-600 text-white rounded text-xs">{{ __('reports.export_format', ['format' => strtoupper(\App\Support\Spreadsheet::extension())]) }}</a>
    @endif
    <a href="{{ route('reports.index') }}" class="text-xs text-gray-500 hover:underline ml-auto"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('reports.all_reports') }}</a>
</form>
