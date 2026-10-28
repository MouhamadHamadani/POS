<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('expenses.recurring.title') }}</h2>
            <div class="flex gap-2">
                <form method="POST" action="{{ route('expenses.recurring.generate') }}">
                    @csrf
                    <button class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">{{ __('expenses.recurring.generate') }}</button>
                </form>
                <a href="{{ route('expenses.recurring.create') }}" class="px-3 py-2 text-sm bg-brand-600 text-white rounded hover:bg-brand-700">{{ __('expenses.recurring.new') }}</a>
            </div>
        </div>
    </x-slot>

    @php
        $usd = fn ($v) => '$'.number_format((float) $v, 2);
        $lbp = fn ($v) => number_format((float) $v).' '.__('shifts.lbp');
        $today = now()->startOfDay();
    @endphp

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
        @include('expenses._tabs', ['active' => 'recurring'])

        @if (session('success'))
            <div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>
        @endif

        <p class="text-sm text-gray-600">{{ __('expenses.recurring.help') }}</p>

        <div class="bg-white rounded-lg shadow-sm overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-start">{{ __('expenses.recurring.cols.expense') }}</th>
                        <th class="p-3 text-end">{{ __('expenses.recurring.cols.amount') }}</th>
                        <th class="p-3 text-start">{{ __('expenses.recurring.cols.schedule') }}</th>
                        <th class="p-3 text-start">{{ __('expenses.recurring.cols.next_due') }}</th>
                        <th class="p-3 text-start">{{ __('expenses.recurring.cols.status') }}</th>
                        <th class="p-3 text-end">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($templates as $t)
                    @php
                        $nextDue = $next[$t->id];
                        $status = ! $t->is_active ? 'paused' : ($nextDue ? 'active' : 'ended');
                        $badge = ['active' => 'bg-green-100 text-green-700', 'paused' => 'bg-amber-100 text-amber-800', 'ended' => 'bg-gray-200 text-gray-600'][$status];
                        $schedule = match ($t->frequency) {
                            'weekly' => __('expenses.recurring.schedule_weekly', ['day' => \App\Support\EnumLabel::get('weekday', (string) $t->day_of_week)]),
                            'monthly' => __('expenses.recurring.schedule_monthly', ['day' => $t->day_of_month]),
                            default => __('expenses.recurring.schedule_yearly', ['date' => $t->start_date->format('d/m')]),
                        };
                    @endphp
                    <tr>
                        <td class="p-3">
                            {{ $t->category->localName() }}
                            <div class="text-xs text-gray-400" dir="auto">{{ $t->description }}</div>
                        </td>
                        <td class="p-3 text-end whitespace-nowrap">
                            <x-ltr>{{ $t->currency === 'lbp' ? $lbp($t->amount_lbp) : $usd($t->amount_usd) }}</x-ltr>
                            <div class="text-xs text-gray-400"><x-ltr>{{ $t->currency === 'lbp' ? $usd($t->amount_usd) : $lbp($t->amount_lbp) }}</x-ltr></div>
                        </td>
                        <td class="p-3 text-xs">
                            {{ \App\Support\EnumLabel::get('frequency', $t->frequency) }}
                            <div class="text-gray-400"><x-ltr>{{ $schedule }}</x-ltr></div>
                        </td>
                        <td class="p-3 whitespace-nowrap text-xs">
                            @if ($status === 'active')
                                <x-ltr><span class="{{ $nextDue->lte($today) ? 'text-red-600 font-medium' : 'text-gray-600' }}">{{ $nextDue->format('Y-m-d') }}</span></x-ltr>
                            @else
                                {{ __('expenses.recurring.none_next') }}
                            @endif
                        </td>
                        <td class="p-3"><span class="text-xs px-2 py-0.5 rounded {{ $badge }}">{{ __('expenses.recurring.status_'.$status) }}</span></td>
                        <td class="p-3 text-end whitespace-nowrap">
                            <a href="{{ route('expenses.recurring.edit', $t) }}" class="text-xs text-brand-600 hover:underline">{{ __('common.edit') }}</a>
                            <form method="POST" action="{{ route('expenses.recurring.toggle', $t) }}" class="inline ms-2">
                                @csrf
                                <button class="text-xs {{ $t->is_active ? 'text-orange-600' : 'text-green-600' }} hover:underline">{{ $t->is_active ? __('expenses.recurring.pause') : __('expenses.recurring.resume') }}</button>
                            </form>
                            <form method="POST" action="{{ route('expenses.recurring.destroy', $t) }}" class="inline ms-2"
                                  onsubmit="return confirm({{ Js::from(__('expenses.recurring.confirm_delete')) }})">
                                @csrf @method('DELETE')
                                <button class="text-xs text-red-600 hover:underline">{{ __('common.delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-10 text-center text-gray-500">{{ __('expenses.recurring.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
