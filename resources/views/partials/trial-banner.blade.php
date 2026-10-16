{{--
    The pre-lock warning: day 10 of the 14-day trial onward, client admin only
    (see layouts/app). Non-blocking — the lock itself is ActivationGate.
--}}
<div class="bg-amber-100 text-amber-900 border-b border-amber-300 px-3 py-1.5 text-center text-xs font-medium flex-shrink-0">
    {{ trans_choice('activation.banner', \App\Support\Activation::daysRemaining()) }}
</div>
