{{-- EN | عربي — fixed order and always visible, whatever the page direction. --}}
@php($current = app()->getLocale())
<div dir="ltr" {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-xs']) }}>
    <a href="{{ route('locale.switch', 'en') }}" @class(['hover:underline', 'font-bold underline' => $current === 'en', 'opacity-70' => $current !== 'en'])>EN</a>
    <span class="opacity-40">|</span>
    <a href="{{ route('locale.switch', 'ar') }}" lang="ar" @class(['hover:underline font-arabic', 'font-bold underline' => $current === 'ar', 'opacity-70' => $current !== 'ar'])>عربي</a>
</div>
