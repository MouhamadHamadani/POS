<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('expenses.duplicate_title') }}</h2>
            <a href="{{ route('expenses.index') }}" class="text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('expenses.back') }}</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-4">
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        @if ($drafts->isEmpty())
            <div class="bg-white rounded-lg shadow-sm p-10 text-center text-gray-500 text-sm">{{ __('expenses.duplicate_none') }}</div>
        @else
            <p class="text-sm text-gray-600">{{ __('expenses.duplicate_help') }}</p>

            <form method="POST" action="{{ route('expenses.duplicate.store') }}" class="bg-white rounded-lg shadow-sm overflow-x-auto">
                @csrf
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="p-3 text-center">{{ __('expenses.include') }}</th>
                            <th class="p-3 text-start">{{ __('expenses.cols.category') }}</th>
                            <th class="p-3 text-start">{{ __('expenses.cols.date') }}</th>
                            <th class="p-3 text-end">{{ __('expenses.cols.amount') }}</th>
                            <th class="p-3 text-start">{{ __('expenses.cols.paid_from') }}</th>
                            <th class="p-3 text-start">{{ __('expenses.cols.notes') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                    @foreach ($drafts as $i => $d)
                        <tr>
                            <td class="p-2 text-center">
                                <input type="checkbox" name="rows[{{ $i }}][include]" value="1" checked />
                                <input type="hidden" name="rows[{{ $i }}][expense_category_id]" value="{{ $d['expense_category_id'] }}" />
                                <input type="hidden" name="rows[{{ $i }}][currency]" value="{{ $d['currency'] }}" />
                                <input type="hidden" name="rows[{{ $i }}][payee]" value="{{ $d['payee'] }}" />
                            </td>
                            <td class="p-2">
                                {{ $d['category'] }}
                                @if ($d['payee'])<div class="text-xs text-gray-400">{{ $d['payee'] }}</div>@endif
                            </td>
                            <td class="p-2"><input type="date" name="rows[{{ $i }}][date]" dir="ltr" value="{{ $d['date'] }}" class="border-gray-300 rounded text-sm py-1" /></td>
                            <td class="p-2 text-end">
                                <div class="flex items-center justify-end gap-2">
                                    <input type="number" name="rows[{{ $i }}][amount]" dir="ltr" min="0" step="0.0001" value="{{ $d['amount'] }}" class="w-32 text-end border-gray-300 rounded text-sm py-1" />
                                    <span class="text-xs text-gray-500">{{ strtoupper($d['currency']) }}</span>
                                </div>
                            </td>
                            <td class="p-2">
                                <select name="rows[{{ $i }}][paid_from]" class="border-gray-300 rounded text-sm py-1">
                                    <option value="outside" @selected($d['paid_from'] === 'outside')>{{ \App\Support\EnumLabel::get('paid_from', 'outside') }}</option>
                                    <option value="drawer" @selected($d['paid_from'] === 'drawer') @disabled(! $canDrawer)>{{ \App\Support\EnumLabel::get('paid_from', 'drawer') }}</option>
                                </select>
                            </td>
                            <td class="p-2"><input type="text" name="rows[{{ $i }}][notes]" dir="auto" value="{{ $d['notes'] }}" class="w-full border-gray-300 rounded text-sm py-1" /></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <div class="border-t p-4 flex justify-end gap-2">
                    <a href="{{ route('expenses.index') }}" class="px-4 py-2 text-sm bg-gray-100 rounded">{{ __('common.cancel') }}</a>
                    <button class="px-4 py-2 text-sm bg-brand-600 text-white rounded hover:bg-brand-700">{{ __('expenses.duplicate_save') }}</button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
