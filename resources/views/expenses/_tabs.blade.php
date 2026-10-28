{{-- Expenses | Recurring. $active: 'expenses' or 'recurring'. --}}
<div class="flex gap-1 border-b border-gray-200 text-sm">
    @foreach (['expenses' => ['expenses.index', 'expenses.tab_expenses'], 'recurring' => ['expenses.recurring.index', 'expenses.tab_recurring']] as $key => [$route, $label])
        <a href="{{ route($route) }}"
           class="px-4 py-2 -mb-px border-b-2 {{ $active === $key ? 'border-brand-600 text-brand-700 font-medium' : 'border-transparent text-gray-500 hover:text-gray-800' }}">{{ __($label) }}</a>
    @endforeach
</div>
