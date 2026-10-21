@php
    // dompdf cannot shape or reorder Arabic, so every string goes through @pdf() (App\Support\PdfText),
    // which hands it over in visual order. Table columns are therefore emitted in reverse for RTL,
    // rather than trusting dompdf's own direction handling.
    $rtl = app()->getLocale() === 'ar';
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>@pdf($title)</title>
<style>
    @font-face { font-family: 'Amiri'; font-weight: normal; font-style: normal; src: url('{{ str_replace('\\', '/', resource_path('fonts/Amiri-Regular.ttf')) }}') format('truetype'); }
    @font-face { font-family: 'Amiri'; font-weight: bold; font-style: normal; src: url('{{ str_replace('\\', '/', resource_path('fonts/Amiri-Bold.ttf')) }}') format('truetype'); }
    body { font-family: {!! $rtl ? "'Amiri', 'DejaVu Sans'" : "'DejaVu Sans'" !!}, sans-serif; font-size: 11px; color: #222; }
    h1 { font-size: 16px; color: #124F4A; margin: 0 0 4px; text-align: {{ $start }}; }
    .meta { color: #555; font-size: 10px; margin-bottom: 12px; text-align: {{ $start }}; }
    table { width: 100%; border-collapse: collapse; @if (isset($maxWidth)) max-width: {{ $maxWidth }}px; @endif }
    th { background: #124F4A; color: white; text-align: {{ $start }}; padding: 6px 8px; font-size: 10px; @unless ($rtl) text-transform: uppercase; @endunless }
    td { padding: 6px 8px; border-bottom: 1px solid #eee; text-align: {{ $start }}; }
    th.num, td.num { text-align: {{ $end }}; }
    tfoot td { background: #f5f7fa; font-weight: bold; border-top: 2px solid #124F4A; }
    h2 { font-size: 12px; color: #124F4A; margin: 16px 0 4px; text-align: {{ $start }}; }
    .center { text-align: center !important; padding: 20px; }
    .indent { color: #555; }
    .heavy td { font-weight: bold; background: #f5f7fa; border-top: 2px solid #124F4A; }
    .big { font-weight: bold; font-size: 14px; color: #124F4A; }
</style>
</head>
<body>
<h1>@pdf($title)</h1>
<div class="meta">@pdf($meta)</div>
@yield('pdf-body')
</body>
</html>
