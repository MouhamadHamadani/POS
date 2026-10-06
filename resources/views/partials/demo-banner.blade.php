{{--
    Shown on every screen of a demo build (see layouts/app, layouts/pos,
    layouts/guest). Bilingual and direction-neutral: it reads the same whether
    the page around it is LTR or RTL.
--}}
<div class="bg-amber-400 text-amber-950 border-b border-amber-500 px-3 py-1 text-center text-xs font-semibold tracking-wide flex-shrink-0"
     dir="ltr">
    <span>{{ __('demo.banner', [], 'en') }}</span>
    <span class="mx-2 opacity-60">•</span>
    <span dir="rtl">{{ __('demo.banner', [], 'ar') }}</span>
</div>
