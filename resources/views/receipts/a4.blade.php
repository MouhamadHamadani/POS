{{--
    A4 invoice for one sale. Every figure is read from the stored sale and its lines
    (see ReceiptPrintService::invoice) — nothing is repriced, so a reprint always
    matches the original. Labels are always English / Arabic; the page itself runs
    right-to-left when the UI language is Arabic.

    $embedded: rendered for a silent print to a named printer. The page is then loaded
    by Electron from a data: URL, so it must not depend on anything outside itself.
--}}
@php
    $embedded = $embedded ?? false;
    $rtl = app()->getLocale() === 'ar';
    $isDemo = \App\Support\Demo::enabled();
    $banners = \App\Services\ReceiptRenderer::banners(false, $isDemo, $isCopy ?? false);

    // "English / العربية" — the Arabic half is its own inline-block so it wraps as a unit.
    $L = function (string $key, array $replace = [], string $group = 'invoice') {
        return new \Illuminate\Support\HtmlString(
            e(__("{$group}.{$key}", $replace, 'en')).' / <span class="ar" dir="rtl">'.e(__("{$group}.{$key}", $replace, 'ar')).'</span>'
        );
    };
    $money = fn ($v) => '$'.number_format((float) $v, 2);
    $rate = fn ($r) => rtrim(rtrim(number_format((float) $r, 2, '.', ''), '0'), '.');
    $nameAr = $business['name_ar'] ?? null;
    $primaryName = $rtl && $nameAr ? $nameAr : $business['name'];
    $secondaryName = $rtl ? ($nameAr ? $business['name'] : null) : $nameAr;
    $footerText = trim((string) $footer) !== '' ? $footer : null;
    $pageOf = __('invoice.page_n_of', ['n' => ':n', 'total' => ':total'], $rtl ? 'ar' : 'en');
    $vatRates = collect($inv['vat_rates'])->map(fn ($r) => $rate($r).'%')->implode(', ');
@endphp
<!doctype html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}" class="{{ $embedded ? 'embedded' : '' }}">
<head>
<meta charset="utf-8">
<title>{{ __('invoice.title', [], 'en') }} {{ $sale->receipt_number }}</title>
<style>
    @unless ($embedded)
    /* Cairo is bundled (public/fonts) so Arabic prints with no network. */
    @font-face { font-family: 'Cairo'; font-weight: 400; src: url('{{ asset('fonts/cairo-arabic-400-normal.woff2') }}') format('woff2');
        unicode-range: U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC; }
    @font-face { font-family: 'Cairo'; font-weight: 700; src: url('{{ asset('fonts/cairo-arabic-700-normal.woff2') }}') format('woff2');
        unicode-range: U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC; }
    @font-face { font-family: 'Cairo'; font-weight: 400; src: url('{{ asset('fonts/cairo-latin-400-normal.woff2') }}') format('woff2');
        unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
    @font-face { font-family: 'Cairo'; font-weight: 700; src: url('{{ asset('fonts/cairo-latin-700-normal.woff2') }}') format('woff2');
        unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
    @endunless
    @page { size: A4; margin: 12mm; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #e5e7eb; color: #111; }
    body { font-family: 'Cairo', 'Segoe UI', Tahoma, Arial, sans-serif; font-size: 10pt; line-height: 1.35; }
    .ar { display: inline-block; }
    .num { text-align: right; direction: ltr; unicode-bidi: isolate; white-space: nowrap; }
    html[dir=rtl] .num { text-align: left; }
    .muted { color: #555; }
    .small { font-size: 9pt; }
    .bold { font-weight: 700; }

    /* One .sheet per printed page. The script below fills them from the flow content and numbers them;
       without it the same content simply flows (and the header row still repeats). */
    #invoice { width: 186mm; margin: 8mm auto; background: #fff; padding: 0; }
    .sheet { width: 186mm; height: 272mm; margin: 8mm auto; background: #fff; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,.25); }
    .sheet > * { flex: none; }
    .pageno { margin-top: auto; text-align: center; font-size: 9pt; color: #555; padding-top: 2mm; }

    .notice { text-align: center; font-weight: 700; border: 1px solid #111; padding: 2mm; margin-bottom: 4mm; }
    .top { display: flex; justify-content: space-between; gap: 10mm; padding-bottom: 4mm; border-bottom: 2px solid #124F4A; }
    .top .biz { flex: 1 1 auto; }
    .top .biz .name { font-size: 16pt; font-weight: 700; color: #124F4A; }
    .top .doc { flex: none; min-width: 62mm; text-align: end; }
    .top .doc .title { font-size: 18pt; font-weight: 700; color: #124F4A; }
    .customer { margin-top: 4mm; padding: 2.5mm 3mm; border: 1px solid #ccc; border-radius: 1.5mm; }

    table.items { width: 100%; border-collapse: collapse; margin-top: 5mm; }
    table.items thead { display: table-header-group; }
    table.items th { background: #124F4A; color: #fff; padding: 1.8mm 2mm; font-size: 9pt; text-align: start; }
    table.items td { padding: 1.6mm 2mm; border-bottom: 1px solid #ddd; vertical-align: top; }
    table.items tr { break-inside: avoid; page-break-inside: avoid; }
    table.items .c-n { width: 8mm; }
    table.items .c-code { width: 24mm; }
    .name-sub { font-size: 8.5pt; color: #666; }

    .tail { margin-top: 5mm; break-inside: avoid; }
    .totals { margin-inline-start: auto; width: 98mm; border: 1px solid #124F4A; border-radius: 1.5mm; padding: 2mm 3mm; }
    .totals table { width: 100%; border-collapse: collapse; }
    .totals td { padding: 0.7mm 0; vertical-align: top; }
    .totals tr.grand td { font-size: 12.5pt; font-weight: 700; color: #124F4A; border-top: 1px solid #124F4A; padding-top: 1.5mm; }
    .totals tr.sep td { border-top: 1px dashed #999; padding-top: 1.5mm; }
    .foot { margin-top: 6mm; text-align: center; font-size: 9pt; color: #333; }

    .actions { position: fixed; top: 0; inset-inline: 0; padding: 2mm; text-align: center; background: #f3f4f6; border-bottom: 1px solid #ccc; direction: ltr; z-index: 5; }
    .actions button { padding: 6px 14px; font-size: 13px; cursor: pointer; }
    body.has-actions { padding-top: 12mm; }
    @media print {
        html, body { background: #fff; }
        .actions { display: none; }
        body.has-actions { padding-top: 0; }
        #invoice { margin: 0; }
        .sheet { margin: 0; box-shadow: none; break-after: page; page-break-after: always; }
        .sheet:last-child { break-after: auto; page-break-after: auto; }
    }
</style>
</head>
<body class="{{ $embedded ? '' : 'has-actions' }}">
@unless ($embedded)
    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('receipt.print') }}</button>
        <button type="button" onclick="if(window.opener||history.length<2){window.close()}else{history.back()}">{{ __('receipt.close') }}</button>
    </div>
@endunless

<main id="invoice">
    <div data-part="top">
        @foreach ($banners as $b)
            <div class="notice">*** {{ $b['label'] }} *** {{ $b['en'] }}<br><span dir="rtl">{{ $b['ar'] }}</span></div>
        @endforeach

        <div class="top">
            <div class="biz">
                <div class="name">{{ $primaryName }}</div>
                @if ($secondaryName)<div class="muted" dir="{{ $rtl ? 'ltr' : 'rtl' }}">{{ $secondaryName }}</div>@endif
                @if ($business['address'])<div class="small">{{ $business['address'] }}</div>@endif
                @if ($business['phone'])<div class="small"><bdi dir="ltr">{{ $business['phone'] }}</bdi></div>@endif
                @if ($business['tax_number'])<div class="small">{!! $L('vat_number') !!}: <bdi dir="ltr">{{ $business['tax_number'] }}</bdi></div>@endif
            </div>
            <div class="doc">
                <div class="title">{!! $L('title') !!}</div>
                <div class="small"><span class="bold">{!! $L('number') !!}:</span> <bdi dir="ltr">{{ $sale->receipt_number }}</bdi></div>
                <div class="small"><span class="bold">{!! $L('date') !!}:</span> <bdi dir="ltr">{{ $sale->created_at->format('Y-m-d H:i') }}</bdi></div>
                <div class="small"><span class="bold">{!! $L('cashier') !!}:</span> {{ $sale->user?->name ?? '—' }}</div>
                @if ($sale->isOnline())
                    <div class="small"><span class="bold">{!! $L('online_order', [], 'receipt') !!}</span>@if ($sale->channel_source) · {!! $L($sale->channel_source, [], 'enums.sale_channel_source') !!}@endif</div>
                @endif
            </div>
        </div>

        @if ($sale->customer)
            <div class="customer small">
                <div><span class="bold">{!! $L('customer') !!}:</span> {{ $sale->customer->name }}</div>
                @if ($sale->customer->phone)<div><span class="bold">{!! $L('phone') !!}:</span> <bdi dir="ltr">{{ $sale->customer->phone }}</bdi></div>@endif
                @if ($sale->customer->address)<div><span class="bold">{!! $L('address') !!}:</span> {{ $sale->customer->address }}</div>@endif
            </div>
        @endif
    </div>

    <table class="items">
        <thead>
            <tr>
                <th class="c-n">#</th>
                <th class="c-code">{!! $L('code') !!}</th>
                <th>{!! $L('item') !!}</th>
                <th class="num">{!! $L('qty') !!}</th>
                <th class="num">{!! $L('unit_price') !!}</th>
                @if ($inv['has_line_discount'])<th class="num">{!! $L('discount') !!}</th>@endif
                <th class="num">{!! $L('line_total') !!}</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($inv['lines'] as $line)
            <tr>
                <td class="c-n muted"><bdi dir="ltr">{{ $line['n'] }}</bdi></td>
                <td class="c-code small"><bdi dir="ltr">{{ $line['code'] ?: '—' }}</bdi></td>
                <td>{{ $rtl && $line['name_ar'] ? $line['name_ar'] : $line['name'] }}</td>
                <td class="num">{{ $line['qty'] }}@if ($line['unit']) {{ \App\Support\EnumLabel::get('unit', $line['unit']) }}@endif</td>
                <td class="num">{{ $money($line['unit_price']) }}</td>
                @if ($inv['has_line_discount'])
                    <td class="num">{{ $line['discount'] > 0 ? '-'.$money($line['discount']) : '' }}</td>
                @endif
                <td class="num">{{ $money($line['total']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div data-part="tail">
        <div class="tail">
            <div class="totals">
                <table>
                    <tr><td>{!! $L('subtotal') !!}</td><td class="num">{{ $money($sale->subtotal_usd) }}</td></tr>
                    @if ($inv['cart_discount'] > 0)
                        @if ($inv['item_discounts'] > 0.00005)
                            <tr><td>{!! $L('item_discounts') !!}</td><td class="num">-{{ $money($inv['item_discounts']) }}</td></tr>
                        @endif
                        <tr><td>{!! $L('cart_discount') !!}</td><td class="num">-{{ $money($inv['cart_discount']) }}</td></tr>
                    @elseif ($inv['discount'] > 0)
                        <tr><td>{!! $L('discount_total') !!}</td><td class="num">-{{ $money($inv['discount']) }}</td></tr>
                    @endif
                    @if ($sale->tax_amount_usd > 0)
                        <tr><td>{!! $vatRates !== '' ? $L('vat_rate', ['rate' => $vatRates]) : $L('vat') !!}</td><td class="num">{{ $money($sale->tax_amount_usd) }}</td></tr>
                    @endif
                    <tr class="grand"><td>{!! $L('total_usd') !!}</td><td class="num">{{ $money($sale->total_usd) }}</td></tr>
                    @if ($sale->total_lbp)
                        <tr><td>{!! $L('total_lbp') !!}</td><td class="num">{{ number_format((float) $sale->total_lbp) }}</td></tr>
                    @endif

                    <tr class="sep"><td>{!! $L('payment') !!}</td><td class="num" style="white-space: normal">{!! $L($sale->payment_method, [], 'enums.payment_method') !!}</td></tr>
                    @foreach ($sale->paymentLines() as $pl)
                        <tr>
                            <td>{!! $L(['cash_usd' => 'tendered_usd', 'cash_lbp' => 'tendered_lbp', 'card' => 'card', 'wish' => 'wish', 'credit' => 'on_account'][$pl['kind']]) !!}@if ($pl['reference']) <span class="small muted">{!! $L('ref') !!} <bdi dir="ltr">{{ $pl['reference'] }}</bdi></span>@endif</td>
                            <td class="num">{{ $pl['currency'] === 'LBP' ? number_format($pl['amount']) : $money($pl['amount']) }}</td>
                        </tr>
                    @endforeach
                    @if ($sale->change_usd > 0)
                        <tr class="bold"><td>{!! $L('change_usd') !!}</td><td class="num">{{ $money($sale->change_usd) }}</td></tr>
                    @endif
                    @if ($sale->change_lbp > 0)
                        <tr class="bold"><td>{!! $L('change_lbp') !!}</td><td class="num">{{ number_format((float) $sale->change_lbp) }}</td></tr>
                    @endif
                    @if ((float) $sale->exchange_rate > 0)
                        <tr class="small muted"><td>{!! $L('exchange_rate') !!}</td><td class="num">1 USD = {{ rtrim(rtrim(number_format((float) $sale->exchange_rate, 2), '0'), '.') }} LBP</td></tr>
                    @endif
                </table>
            </div>
        </div>

        <div class="foot">
            @if ($footerText){{ $footerText }}@else{!! $L('default_footer', [], 'receipt') !!}@endif
            @if ($sale->loyalty_points_earned > 0)<div>{!! $L('loyalty_earned', ['count' => $sale->loyalty_points_earned], 'receipt') !!}</div>@endif
            @foreach ($banners as $b)<div class="bold" style="margin-top: 3mm">*** {{ $b['label'] }} *** {{ $b['en'] }} / <span dir="rtl">{{ $b['ar'] }}</span></div>@endforeach
        </div>
    </div>
</main>

<script>
    // Splits the invoice into A4 pages by measuring real row heights, so every page can carry
    // "Page n of N" (this Chromium cannot count printed pages in CSS). Header row repeats per page.
    (function () {
        var pageOf = @json($pageOf);
        var pristine = document.getElementById('invoice').cloneNode(true);
        var el = function (cls) { var d = document.createElement('div'); d.className = cls; return d; };
        // Does the content plus the page-number line still fit? Measured from the last real block's
        // bottom edge rather than scrollHeight, which also counts collapsed table borders.
        var over = function (s) {
            var k = s.children, no = k[k.length - 1], last = k[k.length - 2];
            return last.getBoundingClientRect().bottom - s.getBoundingClientRect().top + no.offsetHeight > s.clientHeight;
        };

        function paginate() {
            var src = pristine.cloneNode(true);
            var top = src.querySelector('[data-part=top]');
            var tail = src.querySelector('[data-part=tail]');
            var table = src.querySelector('table.items');
            var head = table.tHead;
            var rows = Array.prototype.slice.call(table.tBodies[0].rows);

            var wrap = document.createElement('main');
            wrap.id = 'invoice';
            wrap.style.cssText = 'width:auto;margin:0;background:none';
            document.getElementById('invoice').replaceWith(wrap);

            var sheets = [];
            function sheet(first, withTable) {
                var s = el('sheet'), body = null;
                if (first) s.appendChild(top);
                if (withTable) {
                    var t = table.cloneNode(false);
                    t.appendChild(head.cloneNode(true));
                    body = document.createElement('tbody');
                    t.appendChild(body);
                    s.appendChild(t);
                }
                var n = el('pageno');
                n.innerHTML = '&nbsp;';
                s.appendChild(n);
                wrap.appendChild(s);
                sheets.push({ el: s, body: body, no: n });
                return sheets[sheets.length - 1];
            }

            var cur = sheet(true, true);
            rows.forEach(function (r) {
                cur.body.appendChild(r);
                if (over(cur.el) && cur.body.rows.length > 1) {
                    cur.body.removeChild(r);
                    cur = sheet(false, true);
                    cur.body.appendChild(r);
                }
            });

            cur.el.insertBefore(tail, cur.no);
            if (over(cur.el)) {            // totals do not fit under the last row: give them their own page
                cur.el.removeChild(tail);
                cur = sheet(false, false);
                cur.el.insertBefore(tail, cur.no);
            }

            sheets.forEach(function (s, i) {
                if (sheets.length > 1) s.no.textContent = pageOf.replace(':n', i + 1).replace(':total', sheets.length);
                else s.el.removeChild(s.no);
            });
        }

        paginate();
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(paginate);
    })();
</script>

@if ($autoPrint && ! $embedded)
    <script>
        window.addEventListener('load', () => {
            (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(() => {
                setTimeout(() => {
                    window.print();
                    setTimeout(() => { if (window.opener) window.close(); }, 500);
                }, 250);
            });
        });
    </script>
@endif
</body>
</html>
