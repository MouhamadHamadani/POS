<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const LOCALES = ['en', 'ar'];

    /**
     * user's own language -> this browser session's choice -> the store default
     * (Settings -> language, which also covers guests and new users) -> en.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->language
            ?? session('locale')
            ?? $this->storeDefault();

        if (! in_array($locale, self::LOCALES, true)) {
            $locale = 'en';
        }

        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    /**
     * The settings table may not exist yet (first run, a fresh test database),
     * and a locale lookup must never be what takes the login page down.
     */
    private function storeDefault(): string
    {
        try {
            return (string) Setting::get('language', config('app.locale'));
        } catch (\Throwable) {
            return (string) config('app.locale');
        }
    }
}
