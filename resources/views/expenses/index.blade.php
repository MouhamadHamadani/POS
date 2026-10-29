<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('expenses.title') }}</h2>
            <div class="flex gap-2">
                <a href="{{ route('expenses.duplicate') }}" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">{{ __('expenses.duplicate_last_month') }}</a>
                <a href="{{ route('expenses.create') }}" class="px-3 py-2 text-sm bg-brand-600 text-white rounded hover:bg-brand-700">{{ __('expenses.new') }}</a>
            </div>
        </div>
    </x-slot>

    @php
        $usd = fn ($v) => '$'.number_format((float) $v, 2);
        $lbp = fn ($v) => number_format((float) $v).' '.__('shifts.lbp');
    @endphp

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
        @include('expenses._tabs', ['active' => 'expenses'])

        @if (session('success'))
            <div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>
        @endif
        @error('expense')
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $message }}</div>
        @enderror
        @error('demo')
            <div class="p-2 bg-amber-50 text-amber-800 rounded text-xs">{{ $message }}</div>
        @enderror
        {{-- A rejected date range (to before from) redirects back here; say why instead of silently showing the old results. --}}
        @foreach (['from', 'to'] as $field)
            @error($field)
                <div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $message }}</div>
            @enderror
        @endforeach

        <div class="bg-white rounded-lg shadow-sm p-4">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-2 items-end text-sm">
                <div>
                    <label class="block text-xs text-gray-500">{{ __('reports.from') }}</label>
                    <input type="date" name="from" dir="ltr" value="{{ old('from', $f['from']->format('Y-m-d')) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">{{ __('reports.to') }}</label>
                    <input type="date" name="to" dir="ltr" value="{{ old('to', $f['to']->format('Y-m-d')) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">{{ __('expenses.cols.category') }}</label>
                    <select name="category_id" class="w-full border-gray-300 rounded text-sm">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach ($categories->groupBy('kind') as $kind => $group)
                            <optgroup label="{{ \App\Support\EnumLabel::get('expense_kind', $kind) }}">
                                @foreach ($group as $c)
                                    <option value="{{ $c->id }}" @selected($f['category_id'] === $c->id)>{{ $c->localName() }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">{{ __('expenses.cols.kind') }}</label>
                    <select name="kind" class="w-full border-gray-300 rounded text-sm">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach (\App\Models\ExpenseCategory::KINDS as $k)
                            <option value="{{ $k }}" @selected($f['kind'] === $k)>{{ \App\Support\EnumLabel::get('expense_kind', $k) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">{{ __('expenses.cols.paid_from') }}</label>
                    <select name="paid_from" class="w-full border-gray-300 rounded text-sm">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach (['drawer', 'outside'] as $p)
                            <option value="{{ $p }}" @selected($f['paid_from'] === $p)>{{ \App\Support\EnumLabel::get('paid_from', $p) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <button class="px-3 py-1.5 bg-gray-800 text-white rounded text-xs">{{ __('reports.apply') }}</button>
                    @if (\App\Support\Demo::enabled())
                        <span class="px-3 py-1.5 bg-gray-200 text-gray-500 rounded text-xs" title="{{ __('reports.exports_disabled_title') }}">{{ __('reports.export_disabled') }}</span>
                    @else
                        <a href="{{ route('expenses.export', request()->query()) }}" class="px-3 py-1.5 bg-green-600 text-white rounded text-xs">{{ strtoupper(\App\Support\Spreadsheet::extension()) }}</a>
                    @endif
                </div>
            </form>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            @foreach ([['total', 'total_lbp', 'text-brand-700', 'expenses.total'], ['fixed', 'fixed_lbp', 'text-gray-800', 'expenses.fixed_subtotal'], ['running', 'running_lbp', 'text-gray-800', 'expenses.running_subtotal']] as [$key, $lbpKey, $color, $label])
                <div class="bg-white rounded-lg shadow-card p-4">
                    <div class="text-xs text-gray-500">{{ __($label) }}</div>
                    <div class="text-2xl font-bold {{ $color }} mt-1"><x-ltr>{{ $usd($totals[$key]) }}</x-ltr></div>
                    <div class="text-xs text-gray-500 mt-1"><x-ltr>≈ {{ $lbp($totals[$lbpKey]) }}</x-ltr></div>
                </div>
            @endforeach
        </div>

        <div class="bg-white rounded-lg shadow-sm overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-start">{{ __('expenses.cols.date') }}</th>
                        <th class="p-3 text-start">{{ __('expenses.cols.category') }}</th>
                        <th class="p-3 text-start">{{ __('expenses.cols.payee') }}</th>
                        <th class="p-3 text-end">{{ __('expenses.cols.amount') }}</th>
                        <th class="p-3 text-start">{{ __('expenses.cols.paid_from') }}</th>
                        <th class="p-3 text-start">{{ __('expenses.cols.notes') }}</th>
                        <th class="p-3 text-end">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($rows as $e)
                    <tr>
                        <td class="p-3 whitespace-nowrap text-xs text-gray-600"><x-ltr>{{ $e->date->format('Y-m-d') }}</x-ltr></td>
                        <td class="p-3">
                            {{ $e->category->localName() }}
                            @if ($e->isAutomatic())
                                <span class="ms-1 px-1.5 py-0.5 rounded text-[0.625rem] bg-blue-100 text-blue-700" title="{{ __('expenses.auto_title') }}">{{ __('expenses.auto') }}</span>
                            @endif
                            <div class="text-xs text-gray-400">{{ $e->category->kindLabel() }}</div>
                        </td>
                        <td class="p-3 text-xs">
                            {{ $e->payee }}
                            @if ($e->reference)<div class="text-gray-400"><x-ltr>{{ $e->reference }}</x-ltr></div>@endif
                        </td>
                        <td class="p-3 text-end whitespace-nowrap">
                            <x-ltr>{{ $e->currency === 'lbp' ? $lbp($e->amount_lbp) : $usd($e->amount_usd) }}</x-ltr>
                            <div class="text-xs text-gray-400"><x-ltr>{{ $e->currency === 'lbp' ? $usd($e->amount_usd) : $lbp($e->amount_lbp) }}</x-ltr></div>
                        </td>
                        <td class="p-3 text-xs">{{ \App\Support\EnumLabel::get('paid_from', $e->paid_from) }}</td>
                        <td class="p-3 text-xs text-gray-600" dir="auto">{{ $e->notes }}</td>
                        <td class="p-3 text-end whitespace-nowrap">
                            @if ($e->isLocked())
                                <span class="text-xs text-gray-400" title="{{ __('messages.expense_locked') }}">🔒 {{ __('expenses.locked') }}</span>
                            @else
                                <a href="{{ route('expenses.edit', $e) }}" class="text-xs text-brand-600 hover:underline">{{ __('common.edit') }}</a>
                                <form method="POST" action="{{ route('expenses.destroy', $e) }}" class="inline ms-2"
                                      onsubmit="return confirm({{ Js::from(__('expenses.confirm_delete')) }})">
                                    @csrf @method('DELETE')
                                    <button class="text-xs text-red-600 hover:underline">{{ __('common.delete') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-10 text-center text-gray-500">{{ __('expenses.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $rows->links() }}</div>
    </div>
</x-app-layout>
