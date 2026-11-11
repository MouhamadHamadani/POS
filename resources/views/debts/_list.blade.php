{{-- The receivables table: shared by /debts and the Receivables report. Needs $customers, $totals, $filters,
     and $exportRoute (the route the CSV / PDF links go back to). --}}
@php
    $money = fn ($v) => '$'.number_format((float) $v, 2);
    $query = request()->query();
@endphp

<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    <div class="bg-white rounded-lg shadow-card p-4">
        <div class="text-xs text-gray-500">{{ __('debts.totals_owed') }}</div>
        <div class="text-2xl font-bold text-brand-700 mt-1"><x-ltr>{{ $money($totals['outstanding']) }}</x-ltr></div>
    </div>
    <div class="bg-white rounded-lg shadow-card p-4">
        <div class="text-xs text-gray-500">{{ __('debts.totals_overdue') }}</div>
        <div class="text-2xl font-bold {{ $totals['overdue'] > 0 ? 'text-danger' : 'text-gray-800' }} mt-1"><x-ltr>{{ $money($totals['overdue']) }}</x-ltr></div>
    </div>
    <div class="bg-white rounded-lg shadow-card p-4">
        <div class="text-xs text-gray-500">{{ __('debts.debtors') }}</div>
        <div class="text-2xl font-bold text-gray-800 mt-1"><x-ltr>{{ count($customers) }}</x-ltr></div>
    </div>
    <div class="bg-white rounded-lg shadow-card p-4">
        <div class="text-xs text-gray-500">{{ __('debts.cols.days_90') }}</div>
        <div class="text-2xl font-bold {{ $totals['d90'] > 0 ? 'text-danger' : 'text-gray-800' }} mt-1"><x-ltr>{{ $money($totals['d90']) }}</x-ltr></div>
    </div>
</div>

<form method="GET" class="bg-white rounded-lg shadow-card p-4 flex flex-wrap items-end gap-3 text-sm">
    <div>
        <label class="block text-xs text-gray-500 mb-1">{{ __('debts.search') }}</label>
        <input type="text" name="q" value="{{ $filters['q'] }}" dir="auto" class="border-gray-300 rounded text-sm" />
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">{{ __('debts.sort_by') }}</label>
        <select name="sort" class="border-gray-300 rounded text-sm">
            @foreach (['outstanding', 'overdue', 'name'] as $s)
                <option value="{{ $s }}" @selected($filters['sort'] === $s)>{{ __("debts.sort_$s") }}</option>
            @endforeach
        </select>
    </div>
    <label class="flex items-center gap-2 pb-2"><input type="checkbox" name="overdue" value="1" @checked($filters['overdue']) class="rounded border-gray-300" /> {{ __('debts.overdue_only') }}</label>
    <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm">{{ __('common.filter') }}</button>
    <div class="ms-auto flex gap-2">
        <a href="{{ route($exportRoute, $query + ['format' => 'xlsx']) }}" class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded text-sm">{{ __('debts.export_excel') }}</a>
        <a href="{{ route($exportRoute, $query + ['format' => 'pdf']) }}" class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded text-sm">{{ __('debts.export_pdf') }}</a>
    </div>
</form>

<div class="bg-white rounded-lg shadow-card overflow-x-auto">
    <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
            <tr>
                <th class="p-3 text-start">{{ __('debts.cols.customer') }}</th>
                <th class="p-3 text-end">{{ __('debts.cols.outstanding') }}</th>
                <th class="p-3 text-end">{{ __('debts.cols.overdue') }}</th>
                <th class="p-3 text-end">{{ __('debts.cols.oldest_due') }}</th>
                <th class="p-3 text-end">{{ __('debts.cols.last_payment') }}</th>
                @foreach (\App\Services\CustomerLedgerService::BUCKETS as $b)
                    <th class="p-3 text-end">{{ __("debts.cols.$b") }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y">
        @forelse ($customers as $r)
            <tr class="hover:bg-gray-50">
                <td class="p-3">
                    <a href="{{ route('debts.show', $r['customer']) }}" class="font-medium text-brand-700 hover:underline">{{ $r['customer']->name }}</a>
                    @if ($r['customer']->phone)<div class="text-xs text-gray-500"><x-ltr>{{ $r['customer']->phone }}</x-ltr></div>@endif
                </td>
                <td class="p-3 text-end font-medium"><x-ltr>{{ $money($r['outstanding']) }}</x-ltr></td>
                <td class="p-3 text-end {{ $r['overdue'] > 0 ? 'text-danger font-medium' : 'text-gray-400' }}"><x-ltr>{{ $money($r['overdue']) }}</x-ltr></td>
                <td class="p-3 text-end text-gray-600"><x-ltr>{{ $r['oldest_due']?->format('Y-m-d') ?? '—' }}</x-ltr></td>
                <td class="p-3 text-end text-gray-600"><x-ltr>{{ $r['last_payment']?->format('Y-m-d') ?? '—' }}</x-ltr></td>
                @foreach (\App\Services\CustomerLedgerService::BUCKETS as $b)
                    <td class="p-3 text-end {{ $b !== 'not_due' && $r['aging'][$b] > 0 ? 'text-danger' : 'text-gray-500' }}"><x-ltr>{{ $r['aging'][$b] > 0 ? $money($r['aging'][$b]) : '—' }}</x-ltr></td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="10" class="p-10 text-center text-gray-500">{{ __('debts.none') }}</td></tr>
        @endforelse
        </tbody>
        @if (count($customers))
            <tfoot class="bg-gray-50 font-semibold">
                <tr>
                    <td class="p-3">{{ __('reports.cols.totals') }}</td>
                    <td class="p-3 text-end"><x-ltr>{{ $money($totals['outstanding']) }}</x-ltr></td>
                    <td class="p-3 text-end"><x-ltr>{{ $money($totals['overdue']) }}</x-ltr></td>
                    <td></td><td></td>
                    @foreach (\App\Services\CustomerLedgerService::BUCKETS as $b)
                        <td class="p-3 text-end"><x-ltr>{{ $money($totals[$b]) }}</x-ltr></td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
</div>
