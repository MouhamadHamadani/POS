{{-- Settings > Expenses: add, rename, re-kind and deactivate expense categories. Delete only while nothing was booked to it. --}}
<h3 class="font-semibold mb-1 text-sm">{{ __('expenses.categories.title') }}</h3>
<p class="text-xs text-gray-500 mb-3">{{ __('expenses.categories.help') }}</p>

<table class="min-w-full text-sm mb-4">
    <thead class="text-xs uppercase text-gray-500 border-b">
        <tr>
            <th class="text-start py-2">{{ __('common.name') }}</th>
            <th class="text-start">{{ __('categories.arabic') }}</th>
            <th class="text-start">{{ __('expenses.cols.kind') }}</th>
            <th class="text-end">{{ __('categories.sort') }}</th>
            <th class="text-end">{{ __('expenses.categories.used') }}</th>
            <th class="text-center">{{ __('common.active') }}</th>
            <th></th>
        </tr>
    </thead>
    <tbody class="divide-y">
    @foreach ($expenseCategories as $c)
        <tr>
            <td class="py-2"><input form="cat-{{ $c->id }}" name="name" dir="auto" required value="{{ $c->name }}" class="w-full border-gray-200 rounded text-sm py-1"></td>
            <td class="py-2"><input form="cat-{{ $c->id }}" name="name_ar" dir="rtl" value="{{ $c->name_ar }}" class="w-full border-gray-200 rounded text-sm py-1"></td>
            <td class="py-2">
                <select form="cat-{{ $c->id }}" name="kind" class="border-gray-200 rounded text-sm py-1">
                    @foreach (\App\Models\ExpenseCategory::KINDS as $k)
                        <option value="{{ $k }}" @selected($c->kind === $k)>{{ \App\Support\EnumLabel::get('expense_kind', $k) }}</option>
                    @endforeach
                </select>
            </td>
            <td class="py-2 w-20"><input form="cat-{{ $c->id }}" type="number" name="sort_order" dir="ltr" min="0" value="{{ $c->sort_order }}" class="w-full text-end border-gray-200 rounded text-sm py-1"></td>
            <td class="py-2 text-end text-gray-700"><x-ltr>{{ $c->expenses_count }}</x-ltr></td>
            <td class="py-2 text-center"><input form="cat-{{ $c->id }}" type="checkbox" name="is_active" value="1" @checked($c->is_active)></td>
            <td class="py-2 text-end whitespace-nowrap">
                <button form="cat-{{ $c->id }}" class="text-xs text-brand-600">{{ __('common.save') }}</button>
                @if ($c->expenses_count === 0)
                    <button form="del-{{ $c->id }}" class="text-xs text-red-600 ms-2" title="{{ __('common.delete') }}">×</button>
                @else
                    <span class="text-xs text-gray-400 ms-2" title="{{ __('expenses.categories.in_use') }}">🔒</span>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

@foreach ($expenseCategories as $c)
    <form id="cat-{{ $c->id }}" method="POST" action="{{ route('settings.expense-categories.update', $c) }}">@csrf @method('PUT')</form>
    <form id="del-{{ $c->id }}" method="POST" action="{{ route('settings.expense-categories.destroy', $c) }}"
          onsubmit="return confirm({{ Js::from(__('expenses.categories.confirm_delete', ['name' => $c->name])) }})">@csrf @method('DELETE')</form>
@endforeach

<form method="POST" action="{{ route('settings.expense-categories.store') }}" class="grid grid-cols-1 md:grid-cols-6 gap-2 items-end text-sm border-t pt-4">
    @csrf
    <div class="md:col-span-2">
        <label class="block text-xs text-gray-500">{{ __('categories.name_en') }} *</label>
        <input type="text" name="name" dir="auto" required class="w-full border-gray-300 rounded text-sm" />
    </div>
    <div>
        <label class="block text-xs text-gray-500">{{ __('categories.name_ar') }}</label>
        <input type="text" name="name_ar" dir="rtl" class="w-full border-gray-300 rounded text-sm" />
    </div>
    <div>
        <label class="block text-xs text-gray-500">{{ __('expenses.cols.kind') }}</label>
        <select name="kind" class="w-full border-gray-300 rounded text-sm">
            @foreach (\App\Models\ExpenseCategory::KINDS as $k)
                <option value="{{ $k }}">{{ \App\Support\EnumLabel::get('expense_kind', $k) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500">{{ __('categories.sort') }}</label>
        <input type="number" name="sort_order" dir="ltr" min="0" value="0" class="w-full border-gray-300 rounded text-sm" />
    </div>
    <button class="px-3 py-1.5 bg-brand-600 text-white rounded text-sm">{{ __('common.add') }}</button>
</form>
