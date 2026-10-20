@use('App\Facades\Feature')
@php
    $states = Feature::all();
    $groups = Feature::groups();
    $overrideCount = count(Feature::overrides());
    $coreKeys = array_keys(array_filter($states, fn ($s) => $s['core']));
    $labels = fn (array $keys) => implode(__('features.separator'), array_map(fn ($k) => Feature::label($k), $keys));
    $badge = [
        'plan' => 'bg-gray-100 text-gray-700',
        'override' => 'bg-blue-100 text-blue-800',
        'dependency' => 'bg-amber-100 text-amber-800',
        'core' => 'bg-green-100 text-green-800',
        'demo' => 'bg-purple-100 text-purple-800',
    ];
@endphp

{{-- Per-install deviations from the plan. The form lists every toggleable feature, so an
     unticked box means "off"; the server stores only what differs from the plan. --}}
<section class="border-t pt-6">
    <h3 class="font-semibold mb-1">{{ __('features.section') }}</h3>
    <p class="text-xs text-gray-500 mb-4">{{ __('features.help') }}</p>

    <form id="features-form" method="POST" action="{{ route('settings.features.update') }}" class="space-y-5">
        @csrf
        @foreach ($groups as $group => $keys)
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">{{ Feature::groupLabel($group) }}</h4>
                <ul class="divide-y border rounded">
                    @foreach ($keys as $key)
                        @php $state = $states[$key]; @endphp
                        <li class="flex items-center justify-between gap-3 p-3">
                            <div class="min-w-0">
                                <div>{{ Feature::label($key) }}</div>
                                @if ($state['source'] === 'dependency')
                                    <div class="text-xs text-amber-700">{{ __('features.blocked_needs', ['missing' => $labels($state['missing'])]) }}</div>
                                @endif
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <span class="px-2 py-0.5 rounded text-xs font-medium {{ $badge[$state['source']] ?? $badge['plan'] }}">{{ __('features.source.'.$state['source']) }}</span>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="features[{{ $key }}]" value="1" @checked($state['wanted']) class="sr-only peer"
                                           aria-label="{{ Feature::label($key) }}">
                                    <span class="block w-9 h-5 rounded-full bg-gray-300 peer-checked:bg-brand-600 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-300 transition-colors
                                                 relative after:absolute after:top-0.5 after:start-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition-transform
                                                 peer-checked:after:translate-x-4 rtl:peer-checked:after:-translate-x-4"></span>
                                </label>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </form>

    <details class="mt-4 text-xs text-gray-600">
        <summary class="cursor-pointer">{{ __('features.always_included') }} (<x-ltr>{{ count($coreKeys) }}</x-ltr>)</summary>
        <ul class="mt-2 divide-y border rounded">
            @foreach ($coreKeys as $key)
                <li class="flex items-center justify-between gap-3 p-2">
                    <span>{{ Feature::label($key) }}</span>
                    <span class="px-2 py-0.5 rounded text-xs font-medium {{ $badge['core'] }}">{{ __('features.source.core') }}</span>
                </li>
            @endforeach
        </ul>
    </details>

    <div class="flex items-center justify-between gap-3 mt-4">
        <form method="POST" action="{{ route('settings.features.reset') }}"
              onsubmit="return confirm({{ Js::from(__('features.reset_confirm', ['plan' => __('plan.tier.'.Feature::plan())])) }})">
            @csrf
            <button @disabled($overrideCount === 0)
                    title="{{ $overrideCount === 0 ? __('features.reset_none') : '' }}"
                    class="px-3 py-2 text-sm text-gray-700 bg-gray-100 rounded hover:bg-gray-200 disabled:opacity-50 disabled:cursor-not-allowed">
                {{ __('features.reset') }}@if ($overrideCount) (<x-ltr>{{ $overrideCount }}</x-ltr>)@endif
            </button>
        </form>
        <button form="features-form" class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">{{ __('features.save') }}</button>
    </div>
</section>
