{{-- A customer's statement of account, A4. Follows the receipt language setting (en | ar | bilingual), like every
     printed page. Every figure comes from the ledger (CustomerLedgerService::statement); nothing is recomputed here. --}}
@php
    $receiptLang = in_array($receiptLang ?? 'bilingual', ['en', 'ar', 'bilingual'], true) ? ($receiptLang ?? 'bilingual') : 'bilingual';
    $rtl = $receiptLang === 'ar';
    $langs = $receiptLang === 'bilingual' ? ['en', 'ar'] : [$receiptLang];
    $fontScale = $fontScale ?? 1;
    $embedded = false;
    $L = function (string $key, array $replace = [], string $group = 'debts', string $suffix = '') use ($langs) {
        $parts = [];
        foreach ($langs as $l) {
            $text = e(__("{$group}.{$key}", $replace, $l).$suffix);
            $parts[] = count($langs) === 1 ? $text : ($l === 'ar' ? '<span class="ar" dir="rtl">'.$text.'</span>' : $text.' /');
        }

        return new \Illuminate\Support\HtmlString(implode(' ', $parts));
    };
    $money = fn ($v) => '$'.number_format((float) $v, 2);
    $nameAr = $business['name_ar'] ?? null;
    $primaryName = $rtl && $nameAr ? $nameAr : $business['name'];
    $label = function (string $type) use ($receiptLang) {
        $en = __('enums.ledger_entry.'.$type, [], 'en');
        $ar = __('enums.ledger_entry.'.$type, [], 'ar');

        return $receiptLang === 'ar' ? $ar : ($receiptLang === 'en' ? $en : $en.' / '.$ar);
    };
@endphp
<!doctype html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<title>{{ __('debts.statement_title', [], 'en') }} — {{ $customer->name }}</title>
<style>
    @include('receipts._fonts', ['embedded' => $embedded])
    :root { --fs: {{ $fontScale }}; }
    @page { size: A4; margin: 12mm; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #fff; color: #111; }
    body { font-family: 'Cairo', 'Segoe UI', Tahoma, Arial, sans-serif; font-size: calc(10pt * var(--fs)); line-height: 1.35; width: 186mm; margin: 0 auto; padding: 8mm 0; }
    .ar { display: inline-block; }
    .num { text-align: right; direction: ltr; unicode-bidi: isolate; white-space: nowrap; }
    html[dir=rtl] .num { text-align: left; }
    .muted { color: #555; }
    h1 { font-size: calc(15pt * var(--fs)); margin: 0 0 2mm; color: #124F4A; }
    .top { display: flex; justify-content: space-between; gap: 10mm; border-bottom: 2px solid #124F4A; padding-bottom: 4mm; margin-bottom: 4mm; }
    table { width: 100%; border-collapse: collapse; margin-top: 4mm; }
    th { background: #124F4A; color: #fff; padding: 2mm; font-size: calc(9pt * var(--fs)); text-align: start; }
    td { padding: 1.6mm 2mm; border-bottom: 1px solid #ddd; vertical-align: top; }
    th.num, td.num { text-align: end; }
    tr.edge td { background: #f3f4f6; font-weight: 700; }
    .due { margin-top: 6mm; padding: 4mm; border: 2px solid #124F4A; display: flex; justify-content: space-between; font-size: calc(13pt * var(--fs)); font-weight: 700; }
    .actions { text-align: center; padding: 8mm 0; direction: ltr; }
    @media print { .actions { display: none; } body { padding: 0; } }
</style>
</head>
<body>
    <div class="top">
        <div>
            <div style="font-size: calc(13pt * var(--fs)); font-weight: 700">{{ $primaryName }}</div>
            @if ($receiptLang === 'bilingual' && $nameAr)<div dir="rtl">{{ $nameAr }}</div>@endif
            @if ($business['address'])<div class="muted">{{ $business['address'] }}</div>@endif
            @if ($business['phone'])<div class="muted"><bdi dir="ltr">{{ $business['phone'] }}</bdi></div>@endif
            @if ($business['tax_number'])<div class="muted">{!! $L('vat_number', [], 'receipt', ':') !!} <bdi dir="ltr">{{ $business['tax_number'] }}</bdi></div>@endif
        </div>
        <div style="text-align: end">
            <h1>{!! $L('statement_title') !!}</h1>
            <div class="bold" style="font-weight:700">{{ $customer->name }}</div>
            @if ($customer->phone)<div class="muted"><bdi dir="ltr">{{ $customer->phone }}</bdi></div>@endif
            <div class="muted">{!! $L('statement_period', [], 'debts', ':') !!} <bdi dir="ltr">{{ $from->format('Y-m-d') }} → {{ $to->format('Y-m-d') }}</bdi></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>{!! $L('cols.date') !!}</th><th>{!! $L('cols.entry') !!}</th>
                <th class="num">{!! $L('cols.debit') !!}</th><th class="num">{!! $L('cols.credit') !!}</th><th class="num">{!! $L('cols.balance') !!}</th>
            </tr>
        </thead>
        <tbody>
            <tr class="edge"><td><bdi dir="ltr">{{ $from->format('Y-m-d') }}</bdi></td><td>{!! $L('statement_opening') !!}</td><td></td><td></td><td class="num">{{ $money($statement['opening']) }}</td></tr>
            @foreach ($statement['rows'] as $r)
                <tr>
                    <td><bdi dir="ltr">{{ $r['date']->format('Y-m-d') }}</bdi></td>
                    <td>{{ $label($r['type']) }} <span class="muted"><bdi dir="ltr">{{ $r['receipt'] ?? $r['payment'] ?? '' }}</bdi></span></td>
                    <td class="num">{{ $r['debit'] ? $money($r['debit']) : '' }}</td>
                    <td class="num">{{ $r['credit'] ? $money($r['credit']) : '' }}</td>
                    <td class="num">{{ $money($r['balance']) }}</td>
                </tr>
            @endforeach
            <tr class="edge"><td colspan="2">{!! $L('statement_totals') !!}</td><td class="num">{{ $money($statement['debits']) }}</td><td class="num">{{ $money($statement['credits']) }}</td><td class="num">{{ $money($statement['closing']) }}</td></tr>
        </tbody>
    </table>

    <div class="due"><span>{!! $L('statement_balance_due') !!}</span><span class="num">{{ $money($statement['closing']) }}</span></div>

    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('receipt.print') }}</button>
        <button type="button" onclick="if(window.opener||history.length<2){window.close()}else{history.back()}">{{ __('receipt.close') }}</button>
    </div>

    @if ($autoPrint ?? false)
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 250));</script>
    @endif
</body>
</html>
