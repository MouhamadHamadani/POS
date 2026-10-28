{{-- Inline error for a failed desktop reprint. Goes inside the salesReprint() x-data wrapper. --}}
<div x-show="failed" x-cloak role="alert" class="p-3 bg-red-50 text-red-700 rounded text-sm flex flex-wrap items-center gap-3">
    <span class="flex-1">{{ __('receipt.print_failed') }}</span>
    <button type="button" @click="retry()" class="px-3 py-1.5 rounded bg-red-600 text-white hover:bg-red-700">{{ __('receipt.retry') }}</button>
</div>
