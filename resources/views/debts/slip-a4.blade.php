{{-- The same payment slip on A4 (half a page is plenty): for customers who want a proper acknowledgement. --}}
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
    @page { size: A4; margin: 15mm; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #fff; color: #111; }
    body { font-family: 'Cairo', 'Segoe UI', Tahoma, Arial, sans-serif; font-size: calc(10.5pt * var(--fs)); width: 150mm; margin: 0 auto; padding: 8mm 0; }
    .ar { display: inline-block; }
    .num { text-align: end; direction: ltr; unicode-bidi: isolate; white-space: nowrap; }
    html[dir=rtl] .num { text-align: left; }
    .muted { color: #555; }
    h1 { font-size: calc(16pt * var(--fs)); color: #124F4A; margin: 3mm 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 3mm; }
    td { padding: 1.6mm 2mm; border-bottom: 1px solid #ddd; }
    .big td { font-size: calc(14pt * var(--fs)); font-weight: 700; border-bottom: 2px solid #124F4A; }
    .actions { text-align: center; padding: 8mm 0; direction: ltr; }
    @media print { .actions { display: none; } }
</style>
</head>
<body>
    <div style="font-size: calc(13pt * var(--fs)); font-weight: 700">{{ $primaryName }}</div>
    @if ($receiptLang === 'bilingual' && $nameAr)<div dir="rtl">{{ $nameAr }}</div>@endif
    @if ($business['address'])<div class="muted">{{ $business['address'] }}</div>@endif
    @if ($business['phone'])<div class="muted"><bdi dir="ltr">{{ $business['phone'] }}</bdi></div>@endif

    <h1>{!! $L('slip_title') !!} @if ($payment->isVoided())— {!! $L('slip_voided') !!}@endif</h1>
    <table>
        <tr><td>{!! $L('slip_number') !!}</td><td class="num">{{ $payment->number }}</td></tr>
        <tr><td>{!! $L('cols.date') !!}</td><td class="num">{{ $payment->paid_at->format('Y-m-d H:i') }}</td></tr>
        <tr><td>{!! $L('slip_customer') !!}</td><td>{{ $customer->name }}</td></tr>
        <tr class="big"><td>{!! $L('slip_amount') !!}</td><td class="num">{{ $amount }}</td></tr>
        @if ($payment->currency === 'LBP')
            <tr><td>{!! $L('slip_rate') !!}</td><td class="num">1 USD = {{ number_format((float) $payment->exchange_rate) }} {{ __('shifts.lbp', [], $rtl ? 'ar' : 'en') }} → {{ $money($payment->amount_usd) }}</td></tr>
        @endif
        <tr><td>{!! $L('slip_method') !!}</td><td>{!! $L($payment->method, [], 'enums.debt_method') !!}@if ($payment->reference) · <bdi dir="ltr">{{ $payment->reference }}</bdi>@endif</td></tr>
        @foreach ($applied as $a)
            <tr><td>{!! $L('slip_applied') !!} @if ($a['receipt'])<bdi dir="ltr">{{ $a['receipt'] }}</bdi>@else{!! $L('opening_balance') !!}@endif</td><td class="num">{{ $money($a['amount']) }}</td></tr>
        @endforeach
        <tr><td><strong>{!! $L('slip_balance') !!}</strong></td><td class="num"><strong>{{ $money($balance) }}</strong></td></tr>
        <tr><td>{!! $L('slip_cashier') !!}</td><td>{{ $payment->user?->name ?? '—' }}</td></tr>
    </table>

    @unless ($embedded)
    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('receipt.print') }}</button>
        <button type="button" onclick="if(window.opener||history.length<2){window.close()}else{history.back()}">{{ __('receipt.close') }}</button>
    </div>
    @endunless
    @if (($autoPrint ?? false) && ! $embedded)
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 250));</script>
    @endif
</body>
</html>
