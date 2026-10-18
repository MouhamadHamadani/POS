@php $isEdit = $expense->exists; @endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $isEdit ? __('expenses.edit') : __('expenses.new_title') }}</h2>
            <a href="{{ route('expenses.index') }}" class="text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('expenses.back') }}</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ $isEdit ? route('expenses.update', $expense) : route('expenses.store') }}"
              class="bg-white rounded-lg shadow-sm p-6 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            @csrf
            @if ($isEdit) @method('PUT') @endif

            @php
                $paidFrom = old('paid_from', $expense->paid_from ?: 'outside');
                $currency = old('currency', $expense->currency ?: 'usd');
                $amount = old('amount', $isEdit ? $expense->enteredAmount() : '');
            @endphp

            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('expenses.cols.category') }} *</label>
                <select name="expense_category_id" required class="w-full border-gray-300 rounded text-sm">
                    <option value="">{{ __('purchases.select') }}</option>
                    @foreach ($categories as $kind => $group)
                        <optgroup label="{{ \App\Support\EnumLabel::get('expense_kind', $kind) }}">
                            @foreach ($group as $c)
                                <option value="{{ $c->id }}" @selected((int) old('expense_category_id', $expense->expense_category_id) === $c->id)>{{ $c->localName() }}@unless ($c->is_active) ({{ __('common.inactive') }})@endunless</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('expenses.cols.date') }} *</label>
                <input type="date" name="date" dir="ltr" required value="{{ old('date', $expense->date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" class="w-full border-gray-300 rounded text-sm" />
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
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('expenses.cols.payee') }}</label>
                <input type="text" name="payee" dir="auto" value="{{ old('payee', $expense->payee) }}" class="w-full border-gray-300 rounded text-sm" />
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('expenses.cols.reference') }}</label>
                <input type="text" name="reference" dir="ltr" value="{{ old('reference', $expense->reference) }}" class="w-full border-gray-300 rounded text-sm" />
            </div>

            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('expenses.cols.notes') }}</label>
                <textarea name="notes" dir="auto" rows="2" class="w-full border-gray-300 rounded text-sm">{{ old('notes', $expense->notes) }}</textarea>
            </div>

            <fieldset class="md:col-span-2">
                <legend class="block text-xs font-medium text-gray-600 mb-1">{{ __('expenses.cols.paid_from') }} *</legend>
                <div class="space-y-1">
                    <label class="flex items-center gap-2">
                        <input type="radio" name="paid_from" value="outside" @checked($paidFrom === 'outside') />
                        <span>{{ \App\Support\EnumLabel::get('paid_from', 'outside') }}</span>
                    </label>
                    <label class="flex items-center gap-2 {{ $canDrawer ? '' : 'text-gray-400' }}">
                        <input type="radio" name="paid_from" value="drawer" @checked($paidFrom === 'drawer') @disabled(! $canDrawer) />
                        <span>{{ \App\Support\EnumLabel::get('paid_from', 'drawer') }}</span>
                    </label>
                    @unless ($canDrawer)
                        <p class="text-xs text-gray-500 ms-6">{{ __('expenses.drawer_needs_shift') }}</p>
                    @endunless
                </div>
            </fieldset>

            <div class="md:col-span-2 flex justify-end gap-2 border-t pt-4">
                <a href="{{ route('expenses.index') }}" class="px-4 py-2 text-sm bg-gray-100 rounded">{{ __('common.cancel') }}</a>
                <button class="px-4 py-2 text-sm bg-brand-600 text-white rounded hover:bg-brand-700">{{ $isEdit ? __('common.save_changes') : __('common.save') }}</button>
            </div>
        </form>
    </div>
</x-app-layout>
