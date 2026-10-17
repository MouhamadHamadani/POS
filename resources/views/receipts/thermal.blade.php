@php
    // Receipt language is a store setting (en | ar | bilingual), not the cashier's UI language.
    $receiptLang = in_array($receiptLang ?? 'bilingual', ['en', 'ar', 'bilingual'], true) ? ($receiptLang ?? 'bilingual') : 'bilingual';
    $rtl = $receiptLang === 'ar';
    $langs = $receiptLang === 'bilingual' ? ['en', 'ar'] : [$receiptLang];
    $isDemo = \App\Support\Demo::enabled();
    $isTest = $isTest ?? false; // Settings → Receipt test print; real sales never pass it

    // A label (or value) in the receipt language(s). Bilingual prints "English / العربية";
    // the Arabic half is its own inline-block, so on 58 mm paper it wraps as a unit onto a second line.
    $L = function (string $key, array $replace = [], string $group = 'receipt', string $suffix = '') use ($langs) {
        $parts = [];
        foreach ($langs as $i => $l) {
            $text = e(__("{$group}.{$key}", $replace, $l).$suffix);
            if (count($langs) === 1) {
                $parts[] = $text;
            } elseif ($l === 'ar') {
                $parts[] = '<span class="ar" dir="rtl">'.$text.'</span>';
            } else {
                $parts[] = $text.' /';
            }
        }

        return new \Illuminate\Support\HtmlString(implode(' ', $parts));
    };
    $lbpUnit = __('shifts.lbp', [], $rtl ? 'ar' : 'en');
    // Item sub-line: one short label (English abbreviation unless the receipt is Arabic-only).
    $discLabel = __('receipt.disc', [], $rtl ? 'ar' : 'en');
    $wasLabel = __('receipt.was', [], $rtl ? 'ar' : 'en'); // a line the cashier sold off the price list
    $money = fn ($v) => '$'.number_format((float) $v, 2);
    $footerText = trim((string) $footer) !== '' ? $footer : null;
@endphp
<!doctype html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<title>{{ __('receipt.receipt', [], 'en') }} {{ $sale->receipt_number }}</title>
<style>
    /* Cairo is bundled (public/fonts) so Arabic prints with no network; Courier New has no Arabic glyphs. */
    @font-face { font-family: 'Cairo'; font-weight: 400; src: url('{{ asset('fonts/cairo-arabic-400-normal.woff2') }}') format('woff2');
        unicode-range: U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC; }
    @font-face { font-family: 'Cairo'; font-weight: 700; src: url('{{ asset('fonts/cairo-arabic-700-normal.woff2') }}') format('woff2');
        unicode-range: U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC; }
    @font-face { font-family: 'Cairo'; font-weight: 400; src: url('{{ asset('fonts/cairo-latin-400-normal.woff2') }}') format('woff2');
        unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
    @font-face { font-family: 'Cairo'; font-weight: 700; src: url('{{ asset('fonts/cairo-latin-700-normal.woff2') }}') format('woff2');
        unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
    @page { size: {{ $width }}mm auto; margin: 0; }
    html, body { margin: 0; padding: 0; background: white; font-family: 'Cairo', 'Courier New', monospace; }
    body { width: {{ $width }}mm; padding: 4mm; font-size: 11px; color: #111; }
    .center { text-align: center; }
    /* Amounts: left-to-right, isolated, and always on the same (end) edge of the line. */
    .amt    { text-align: right; direction: ltr; unicode-bidi: isolate; white-space: nowrap; padding-inline-start: 2mm; }
    html[dir=rtl] .amt { text-align: left; }
    .ar     { display: inline-block; }
    .name-ar { font-size: 10px; }
    .small  { font-size: 10px; }
    .bold   { font-weight: bold; }
    hr { border: none; border-top: 1px dashed #555; margin: 3mm 0; }
    table { width: 100%; border-collapse: collapse; font-size: 11px; }
    td { padding: 1mm 0; vertical-align: top; }
    .item-row td { padding-top: 0.5mm; padding-bottom: 0.5mm; }
    .totals td { padding: 0.5mm 0; }
    .grand { font-size: 13px; font-weight: bold; }
    .actions { padding: 10mm 0; text-align: center; direction: ltr; }
    .actions button { padding: 8px 16px; font-size: 14px; cursor: pointer; }
    @media print { .actions { display: none; } }
</style>
</head>
<body>
    @php
        // Wording shared with App\Services\ReceiptRenderer, which stamps the same
        // notice onto a Premium custom template. Only the markup differs.
        $notice = \App\Services\ReceiptRenderer::noticeLines($isTest, $isDemo);
    @endphp

    {{-- A demo/test printout must never be able to pass as a transaction record.
         Both languages, always — whatever receipt language is set. Short lines so it
         still fits 58mm (32 char) paper, not just 80mm. --}}
    @if ($notice)
        <div class="center bold">*** {{ $notice['label'] }} ***</div>
        <div class="center bold">{{ $notice['en'] }}</div>
        <div class="center bold" dir="rtl">{{ $notice['ar'] }}</div>
        <hr>
    @endif

    @php
        $nameAr = $business['name_ar'] ?? null;
        $primaryName = $rtl && $nameAr ? $nameAr : $business['name'];
    @endphp
    <div class="center bold">{{ $primaryName }}</div>
    @if ($receiptLang === 'bilingual' && $nameAr) <div class="center" dir="rtl">{{ $nameAr }}</div> @endif
    @if ($business['address']) <div class="center small">{{ $business['address'] }}</div> @endif
    @if ($business['phone']) <div class="center small"><bdi dir="ltr">{{ $business['phone'] }}</bdi></div> @endif
    @if ($business['tax_number']) <div class="center small">{!! $L('vat_number', [], 'receipt', ':') !!} <bdi dir="ltr">{{ $business['tax_number'] }}</bdi></div> @endif
    @if ($header)<div class="center small">{{ $header }}</div>@endif

    <hr>
    <div class="small">
        <strong>{!! $L('receipt', [], 'receipt', ':') !!}</strong> <bdi dir="ltr">{{ $sale->receipt_number }}</bdi><br>
        <strong>{!! $L('date', [], 'receipt', ':') !!}</strong> <bdi dir="ltr">{{ $sale->created_at->format('Y-m-d H:i') }}</bdi><br>
        <strong>{!! $L('cashier', [], 'receipt', ':') !!}</strong> {{ $sale->user?->name ?? '—' }}<br>
        @if ($sale->customer)
            <strong>{!! $L('customer', [], 'receipt', ':') !!}</strong> {{ $sale->customer->name }}<br>
        @endif
        @if (($sale->price_tier ?? 'retail') !== 'retail')
            <strong>{!! $L('price_list', [], 'pos.receipt', ':') !!}</strong> {!! $L($sale->price_tier, [], 'pos.tier') !!}<br>
        @endif
    </div>
    <hr>

    <table>
        @foreach ($sale->items as $item)
            @php
                $itemAr = $item->product?->name_ar;
                $itemName = $rtl && $itemAr ? $itemAr : $item->product_name;
                $itemSecondary = $receiptLang === 'bilingual' && $itemAr && $itemAr !== $item->product_name ? $itemAr : null;
            @endphp
        <tr class="item-row">
            <td colspan="2">{{ $itemName }}@if ($itemSecondary)<br><span class="name-ar" dir="rtl">{{ $itemSecondary }}</span>@endif</td>
        </tr>
        <tr class="item-row small">
            <td>
                <bdi dir="ltr">{{ rtrim(rtrim(number_format((float) $item->qty, 4, '.', ''), '0'), '.') }}
                × {{ $money($item->unit_price_usd) }}</bdi>
                @if ($item->isPriceOverridden() && $item->original_unit_price_usd !== null)
                    <br>&nbsp;&nbsp;{{ $wasLabel }} <s><bdi dir="ltr">{{ $money($item->original_unit_price_usd) }}</bdi></s>
                @endif
                @if ($item->discount_amount_usd > 0)
                    <br>&nbsp;&nbsp;{{ $discLabel }} @if ($rtl)<bdi dir="ltr">-{{ $money($item->discount_amount_usd) }}</bdi>@else-{{ $money($item->discount_amount_usd) }}@endif
                @endif
            </td>
            <td class="amt">{{ $money($item->line_total_usd) }}</td>
        </tr>
        @endforeach
    </table>

    <hr>
    <table class="totals">
        <tr><td>{!! $L('subtotal') !!}</td><td class="amt">{{ $money($sale->subtotal_usd) }}</td></tr>
        @if ((float) $sale->cart_discount_usd > 0)
            {{-- discount_amount_usd is every discount; the cart slice is already inside it, so split it, don't add. --}}
            @if ((float) $sale->discount_amount_usd - (float) $sale->cart_discount_usd > 0.00005)
                <tr><td>{!! $L('item_discounts', [], 'pos.receipt') !!}</td><td class="amt">-{{ $money((float) $sale->discount_amount_usd - (float) $sale->cart_discount_usd) }}</td></tr>
            @endif
            <tr><td>{!! $L('cart_discount', [], 'pos.receipt') !!}</td><td class="amt">-{{ $money($sale->cart_discount_usd) }}</td></tr>
        @elseif ($sale->discount_amount_usd > 0)
            <tr><td>{!! $L('discount') !!}</td><td class="amt">-{{ $money($sale->discount_amount_usd) }}</td></tr>
        @endif
        @if ($sale->tax_amount_usd > 0)
            <tr><td>{!! $L('vat') !!}</td><td class="amt">{{ $money($sale->tax_amount_usd) }}</td></tr>
        @endif
        <tr class="grand"><td>{!! $L('total') !!}</td><td class="amt">{{ $money($sale->total_usd) }}</td></tr>
        @if ($sale->total_lbp)
            <tr class="small"><td>{!! $L('lbp_equiv') !!}</td><td class="amt">{{ number_format((float) $sale->total_lbp) }}</td></tr>
        @endif
    </table>

    <hr>
    <table class="small">
        <tr><td>{!! $L('payment') !!}</td><td class="amt" style="white-space: normal;">{!! $L($sale->payment_method, [], 'enums.payment_method') !!}</td></tr>
        @if ($sale->amount_tendered_usd)
            <tr><td>{!! $L('cash_usd_tendered') !!}</td><td class="amt">{{ $money($sale->amount_tendered_usd) }}</td></tr>
        @endif
        @if ($sale->amount_tendered_lbp)
            <tr><td>{!! $L('cash_lbp_tendered') !!}</td><td class="amt">{{ number_format((float) $sale->amount_tendered_lbp) }} {{ $lbpUnit }}</td></tr>
        @endif
        @if ($sale->amount_card_usd > 0)
            <tr><td>{!! $L('card') !!}</td><td class="amt">{{ $money($sale->amount_card_usd) }}</td></tr>
        @endif
        @if ($sale->amount_credit_usd > 0)
            <tr><td>{!! $L('on_account') !!}</td><td class="amt">{{ $money($sale->amount_credit_usd) }}</td></tr>
        @endif
        @if ($sale->change_usd > 0)
            <tr class="bold"><td>{!! $L('change_usd') !!}</td><td class="amt">{{ $money($sale->change_usd) }}</td></tr>
        @endif
        @if ($sale->change_lbp > 0)
            <tr class="bold"><td>{!! $L('change_lbp') !!}</td><td class="amt">{{ number_format((float) $sale->change_lbp) }}</td></tr>
        @endif
    </table>

    <hr>
    <div class="center small">{{ $footerText }}@unless ($footerText){!! $L('default_footer') !!}@endunless</div>

    @if ($sale->loyalty_points_earned > 0)
        <div class="center small">{!! $L('loyalty_earned', ['count' => $sale->loyalty_points_earned]) !!}</div>
    @endif

    <div class="center small" style="margin-top: 6mm;">{!! $L('receipt_no') !!} <bdi dir="ltr">{{ $sale->receipt_number }}</bdi></div>

    @if ($notice)
        <hr>
        <div class="center bold">*** {{ $notice['label'] }} ***</div>
        <div class="center bold">{{ $notice['en'] }}</div>
        <div class="center bold" dir="rtl">{{ $notice['ar'] }}</div>
    @endif

    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('receipt.print') }}</button>
        <button type="button" onclick="window.close()">{{ __('receipt.close') }}</button>
    </div>

    @if ($autoPrint)
        <script>
            window.addEventListener('load', () => {
                setTimeout(() => {
                    window.print();
                    setTimeout(() => window.close(), 500);
                }, 250);
            });
        </script>
    @endif
</body>
</html>
