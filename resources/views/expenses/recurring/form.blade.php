@php $isEdit = $template->exists; @endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $isEdit ? __('expenses.recurring.edit') : __('expenses.recurring.new_title') }}</h2>
            <a href="{{ route('expenses.recurring.index') }}" class="text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('expenses.recurring.back') }}</a>
        </div>
    </x-slot>

    @php
        $freq = old('frequency', $template->frequency);
        $currency = old('currency', $template->currency ?: 'usd');
        $amount = old('amount', $isEdit ? $template->enteredAmount() : '');
        $active = old('is_active', $template->is_active ? 1 : 0);
    @endphp

    <div class="py-6 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ $isEdit ? route('expenses.recurring.update', $template) : route('expenses.recurring.store') }}"
              x-data="{ freq: @js($freq) }"
              class="bg-white rounded-lg shadow-sm p-6 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            @csrf
            @if ($isEdit) @method('PUT') @endif

            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('expenses.cols.category') }} *</label>
                <select name="expense_category_id" required class="w-full border-gray-300 rounded text-sm">
                    <option value="">{{ __('purchases.select') }}</option>
                    @foreach ($categories as $kind => $group)
                        <optgroup label="{{ \App\Support\EnumLabel::get('expense_kind', $kind) }}">
                            @foreach ($group as $c)
                                <option value="{{ $c->id }}" @selected((int) old('expense_category_id', $template->expense_category_id) === $c->id)>{{ $c->localName() }}@unless ($c->is_active) ({{ __('common.inactive') }})@endunless</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('expenses.recurring.description') }}</label>
                <input type="text" name="description" dir="auto" maxlength="255" value="{{ old('description', $template->description) }}" class="w-full border-gray-300 rounded text-sm" />
                <p class="text-xs text-gray-500 mt-1">{{ __('expenses.recurring.description_help') }}</p>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('expenses.cols.amount') }} *</label>
                <div class="flex gap-2">
                    <input type="number" name="amount" dir="ltr" required min="0" step="0.0001" value="{{ $amount }}" class="w-full border-gray-300 rounded text-sm" />
                    <select name="currency" class="border-gray-300 rounded text-sm">
                        <option value="usd" @selected($currency === 'usd')>USD</option>
                        <option value="lbp" @selected($currency === 'lbp')>LBP</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('expenses.recurring.frequency') }} *</label>
                <select name="frequency" x-model="freq" class="w-full border-gray-300 rounded text-sm">
                    @foreach (\App\Models\RecurringExpense::FREQUENCIES as $f)
                        <option value="{{ $f }}">{{ \App\Support\EnumLabel::get('frequency', $f) }}</option>
                    @endforeach
                </select>
            </div>

            <div x-show="freq === 'weekly'" x-cloak>
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('expenses.recurring.day_of_week') }} *</label>
                <select name="day_of_week" :disabled="freq !== 'weekly'" class="w-full border-gray-300 rounded text-sm">
                    @foreach (range(1, 7) as $d)
                        <option value="{{ $d }}" @selected((int) old('day_of_week', $template->day_of_week ?: 1) === $d)>{{ \App\Support\EnumLabel::get('weekday', (string) $d) }}</option>
                    @endforeach
                </select>
            </div>

            <div x-show="freq === 'monthly'" x-cloak>
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('expenses.recurring.day_of_month') }} *</label>
                <input type="number" name="day_of_month" dir="ltr" min="1" max="31" step="1" :disabled="freq !== 'monthly'"
                       value="{{ old('day_of_month', $template->day_of_month ?: 1) }}" class="w-full border-gray-300 rounded text-sm" />
                <p class="text-xs text-gray-500 mt-1">{{ __('expenses.recurring.day_of_month_help') }}</p>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('expenses.recurring.start_date') }} *</label>
                <input type="date" name="start_date" dir="ltr" required value="{{ old('start_date', $template->start_date?->format('Y-m-d')) }}" class="w-full border-gray-300 rounded text-sm" />
                <p x-show="freq === 'yearly'" x-cloak class="text-xs text-gray-500 mt-1">{{ __('expenses.recurring.start_date_yearly') }}</p>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('expenses.recurring.end_date') }}</label>
                <input type="date" name="end_date" dir="ltr" value="{{ old('end_date', $template->end_date?->format('Y-m-d')) }}" class="w-full border-gray-300 rounded text-sm" />
            </div>

            <label class="md:col-span-2 flex items-center gap-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked((int) $active === 1) class="rounded border-gray-300" />
                <span>{{ __('expenses.recurring.active') }}</span>
            </label>

            <div class="md:col-span-2 flex justify-end gap-2 border-t pt-4">
                <a href="{{ route('expenses.recurring.index') }}" class="px-4 py-2 text-sm bg-gray-100 rounded">{{ __('common.cancel') }}</a>
                <button class="px-4 py-2 text-sm bg-brand-600 text-white rounded hover:bg-brand-700">{{ $isEdit ? __('common.save_changes') : __('common.save') }}</button>
            </div>
        </form>
    </div>
</x-app-layout>
