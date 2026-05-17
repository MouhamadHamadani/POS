<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Report' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 16px; color: #1B3A6B; margin: 0 0 4px; }
        .meta { color: #555; font-size: 10px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1B3A6B; color: white; text-align: left; padding: 6px 8px; font-size: 10px; text-transform: uppercase; }
        td { padding: 6px 8px; border-bottom: 1px solid #eee; }
        tfoot td { background: #f5f7fa; font-weight: bold; border-top: 2px solid #1B3A6B; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        Generated {{ now()->format('Y-m-d H:i') }}
        @isset($from)
            · Period {{ $from->format('Y-m-d') }} to {{ $to->format('Y-m-d') }}
        @endisset
    </div>
    {{ $slot ?? '' }}
</body>
</html>
