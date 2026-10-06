{{-- Keeps a value left-to-right inside Arabic text: amounts, quantities, barcodes,
     SKUs, phone numbers, document numbers, dates, usernames. No visual change in LTR pages. --}}
<bdi dir="ltr" {{ $attributes->merge(['class' => 'ltr-iso']) }}>{{ $slot }}</bdi>
