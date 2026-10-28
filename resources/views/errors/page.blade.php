{{-- Every failed page: logo, the name of the error, a Go home button. See App\Support\ErrorPages.
     Standalone on purpose (inline CSS, no Vite, no layout, no database): this page must still
     render when the app itself is what broke. $code: HTTP status. $detail: a message the app wrote itself, or null. --}}
@php
    $locale = app()->getLocale();
    $rtl = $locale === 'ar';
    $key = trans()->has("errors.codes.{$code}.title") ? "errors.codes.{$code}" : 'errors.generic';
    $title = __("{$key}.title");
    $message = $detail ?? __("{$key}.message");
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $code }} · {{ $title }} · {{ config('app.name', 'LebaSouk') }}</title>
<style>
    @include('receipts._fonts')
    * { box-sizing: border-box; }
    html, body { margin: 0; min-height: 100%; }
    body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px;
           background: #f1f5f9; color: #1f2937; font-family: 'Cairo', 'Segoe UI', Tahoma, Arial, sans-serif; }
    .card { width: 100%; max-width: 440px; background: #fff; border-radius: 12px; padding: 40px 32px 32px; text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,.1), 0 8px 24px rgba(0,0,0,.06); }
    .logo { width: 72px; height: 72px; border-radius: 14px; }
    .brand { margin-top: 8px; font-weight: 700; color: #124F4A; letter-spacing: .02em; }
    .code { margin-top: 28px; font-size: 13px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #E2673F; }
    h1 { margin: 6px 0 0; font-size: 26px; line-height: 1.25; }
    p { margin: 12px 0 0; font-size: 15px; line-height: 1.6; color: #4b5563; }
    .actions { margin-top: 28px; display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; }
    .btn { display: inline-block; padding: 9px 20px; border-radius: 8px; border: 1px solid #124F4A; font: inherit; font-size: 14px; font-weight: 600;
           text-decoration: none; cursor: pointer; background: #124F4A; color: #fff; }
    .btn:hover { background: #0e3f3b; }
    .btn.secondary { background: #fff; color: #124F4A; }
    .btn.secondary:hover { background: #f0f7f6; }
    [hidden] { display: none !important; }
</style>
</head>
<body>
<main class="card">
    <x-brand-mark class="logo" />
    <div class="brand">{{ config('app.name', 'LebaSouk') }}</div>

    <div class="code">{{ __('errors.error') }} <bdi dir="ltr">{{ $code }}</bdi></div>
    <h1>{{ $title }}</h1>
    <p>{{ $message }}</p>

    <div class="actions">
        <a class="btn" href="{{ url('/') }}">{{ __('errors.go_home') }}</a>
        <button type="button" class="btn secondary" id="go-back" hidden onclick="history.back()">{{ __('errors.go_back') }}</button>
    </div>
</main>
<script>
    // The desktop window has no back button: offer one only when there is somewhere to go back to.
    if (history.length > 1) document.getElementById('go-back').hidden = false;
</script>
</body>
</html>
