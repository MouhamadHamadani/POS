{{-- Payment slip for a customer's repayment, thermal paper (80 / 58 mm). Follows the receipt language setting.
     $embedded: printed silently from a data: URL by the desktop app (no buttons, fonts inline). --}}
@php
    $receiptLang = in_array($receiptLang ?? 'bilingual', ['en', 'ar', 'bilingual'], true) ? ($receiptLang ?? 'bilingual') : 'bilingual';
    $rtl = $receiptLang === 'ar';
    $langs = $receiptLang === 'bilingual' ? ['en', 'ar'] : [$receiptLang];
    $fontScale = $fontScale ?? 1;
    $embedded = $embedded ?? false;
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
    $amount = $payment->currency === 'LBP' ? number_format((float) $payment->amount).' '.__('shifts.lbp', [], $rtl ? 'ar' : 'en') : $money($payment->amount);
@endphp
<!doctype html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<title>{{ $payment->number }}</title>
<style>
    @include('receipts._fonts', ['embedded' => $embedded])
    :root { --fs: {{ $fontScale }}; }
    @page { size: {{ $width }}mm auto; margin: 0; }
    html, body { margin: 0; padding: 0; background: white; font-family: 'Cairo', 'Courier New', monospace; }
    body { box-sizing: border-box; width: {{ $width }}mm; padding: 4mm; font-size: calc(11px * var(--fs)); color: #111; }
    .center { text-align: center; }
    .amt { text-align: right; direction: ltr; unicode-bidi: isolate; white-space: nowrap; padding-inline-start: 2mm; }
    html[dir=rtl] .amt { text-align: left; }
    .ar { display: inline-block; }
    .small { font-size: calc(10px * var(--fs)); }
    .bold { font-weight: bold; }
    hr { border: none; border-top: 1px dashed #555; margin: 3mm 0; }
    table { width: 100%; border-collapse: collapse; font-size: calc(11px * var(--fs)); }
    td { padding: 0.8mm 0; vertical-align: top; }
    .grand { font-size: calc(13px * var(--fs)); font-weight: bold; }
    .actions { padding: 10mm 0; text-align: center; direction: ltr; }
    @media print { .actions { display: none; } }
</style>
</head>
<body>
    <div class="center bold">{{ $primaryName }}</div>
    @if ($receiptLang === 'bilingual' && $nameAr)<div class="center" dir="rtl">{{ $nameAr }}</div>@endif
    @if ($business['phone'])<div class="center small"><bdi dir="ltr">{{ $business['phone'] }}</bdi></div>@endif
    <hr>
    <div class="center bold">{!! $L('slip_title') !!}</div>
    @if ($payment->isVoided())<div class="center bold">*** {!! $L('slip_voided') !!} ***</div>@endif
    <hr>
    <div class="small">
        <strong>{!! $L('slip_number', [], 'debts', ':') !!}</strong> <bdi dir="ltr">{{ $payment->number }}</bdi><br>
        <strong>{!! $L('cols.date', [], 'debts', ':') !!}</strong> <bdi dir="ltr">{{ $payment->paid_at->format('Y-m-d H:i') }}</bdi><br>
        <strong>{!! $L('slip_customer', [], 'debts', ':') !!}</strong> {{ $customer->name }}<br>
        <strong>{!! $L('slip_cashier', [], 'debts', ':') !!}</strong> {{ $payment->user?->name ?? '—' }}
    </div>
    <hr>
    <table>
        <tr class="grand"><td>{!! $L('slip_amount') !!}</td><td class="amt">{{ $amount }}</td></tr>
        @if ($payment->currency === 'LBP')
            <tr class="small"><td>{!! $L('slip_rate') !!}</td><td class="amt">1 USD = {{ number_format((float) $payment->exchange_rate) }} {{ __('shifts.lbp', [], $rtl ? 'ar' : 'en') }}</td></tr>
            <tr class="small"><td>USD</td><td class="amt">{{ $money($payment->amount_usd) }}</td></tr>
        @endif
        <tr class="small"><td>{!! $L('slip_method') !!}</td><td class="amt" style="white-space: normal">{!! $L($payment->method, [], 'enums.debt_method') !!}@if ($payment->reference)<br><bdi dir="ltr">{{ $payment->reference }}</bdi>@endif</td></tr>
    </table>
    @if ($applied->isNotEmpty())
        <hr>
        <div class="small bold">{!! $L('slip_applied', [], 'debts', ':') !!}</div>
        <table class="small">
            @foreach ($applied as $a)
                <tr><td>@if ($a['receipt'])<bdi dir="ltr">{{ $a['receipt'] }}</bdi>@else{!! $L('opening_balance') !!}@endif</td><td class="amt">{{ $money($a['amount']) }}</td></tr>
            @endforeach
        </table>
    @endif
    <hr>
    <table>
        <tr class="bold"><td>{!! $L('slip_balance') !!}</td><td class="amt">{{ $money($balance) }}</td></tr>
    </table>
    <hr>
    <div class="center small">{!! $L('slip_thanks') !!}</div>

    @unless ($embedded)
    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('receipt.print') }}</button>
        <button type="button" onclick="if(window.opener||history.length<2){window.close()}else{history.back()}">{{ __('receipt.close') }}</button>
    </div>
    @endunless
    @if (($autoPrint ?? false) && ! $embedded)
        <script>
            window.addEventListener('load', () => setTimeout(() => { window.print(); setTimeout(() => { if (window.opener) window.close(); }, 500); }, 250));
        </script>
    @endif
</body>
</html>
