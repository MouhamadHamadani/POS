@props(['sale'])
@php
    $class = match ($sale->status) {
        'completed' => 'bg-green-100 text-green-700',
        'partial_refund' => 'bg-amber-100 text-amber-700',
        'refunded' => 'bg-blue-100 text-blue-700',
        'voided' => 'bg-red-100 text-red-700',
        default => 'bg-gray-100 text-gray-700',
    };
@endphp
<span {{ $attributes->merge(['class' => "text-xs px-2 py-0.5 rounded {$class}"]) }}>{{ $sale->statusLabel() }}</span>
