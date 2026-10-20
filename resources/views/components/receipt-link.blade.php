{{-- A receipt number that opens the sale in Sales History -- but only for someone who may open it
     (Sale::canBeSeenBy + the Sales History roles); anyone else gets the plain number, not a link
     to a page that would refuse them. Needs the sale's id, receipt_number and user_id loaded. --}}
@props(['sale'])
@php $viewer = auth()->user(); @endphp
@if ($viewer?->canViewSalesHistory() && $sale->canBeSeenBy($viewer))
    <a href="{{ route('sales.show', $sale->id) }}" class="text-brand-700 hover:underline"><x-ltr>{{ $sale->receipt_number }}</x-ltr></a>
@else
    <x-ltr>{{ $sale->receipt_number }}</x-ltr>
@endif
