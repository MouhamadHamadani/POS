<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * What the till shows when a request fails, wired up in bootstrap/app.php.
 *
 * The desktop window has no address bar and no back button, so a bare "419 Page Expired" is a
 * dead end that only closing the app gets out of. Two things replace it:
 *
 *  - an expired CSRF token on a signed-out page (the login form left open for hours, a page
 *    restored from the back/forward cache) goes straight back to the login page with a notice
 *    and a fresh token, which is all the person was going to do anyway;
 *  - every other failed page (403, 404, 419 while signed in, 500...) gets one branded page:
 *    the logo, the name of the error and a Go home button (resources/views/errors/page.blade.php).
 *
 * Nothing here may fail: an error page that throws turns a bad request into a blank window.
 */
final class ErrorPages
{
    /** Statuses whose own words are worth showing: abort(403, '...') messages are written by this app. */
    private const SHOW_MESSAGE = [403];

    public static function tokenMismatch(Request $request): ?Response
    {
        self::useLocale($request);

        if ($request->expectsJson()) {
            $message = __('errors.session_expired_json');

            return response()->json(['message' => $message, 'error' => $message], 419);
        }

        // Signed in with a valid session and still a wrong token (a second tab): the error page says so.
        if (self::signedIn($request)) {
            return null;
        }

        return redirect()->route('login')
            ->withInput($request->except(['password', 'password_confirmation', '_token']))
            ->with('expired', __('errors.session_expired'));
    }

    public static function page(Response $response, Throwable $e, Request $request): Response
    {
        $status = $response->getStatusCode();

        if ($status < 400 || $request->expectsJson()) {
            return $response;
        }

        // In development an unhandled exception keeps its stack trace.
        if (config('app.debug') && ! $e instanceof HttpExceptionInterface) {
            return $response;
        }

        try {
            self::useLocale($request);

            $detail = trim($e->getMessage());
            if (! in_array($status, self::SHOW_MESSAGE, true) || ! $e instanceof HttpExceptionInterface
                || $detail === '' || $detail === 'Forbidden' || $detail === 'This action is unauthorized.') {
                $detail = null;
            }

            $page = response()->view('errors.page', ['code' => $status, 'detail' => $detail], $status);
            if ($retry = $response->headers->get('Retry-After')) {
                $page->headers->set('Retry-After', $retry);
            }

            return $page;
        } catch (Throwable) {
            return $response;
        }
    }

    /**
     * The 419 is thrown by the CSRF middleware, which runs before SetLocale in the web group, and
     * a 404 never reaches the group at all: apply the same rule (user, session, store) by hand.
     */
    private static function useLocale(Request $request): void
    {
        try {
            app(SetLocale::class)->handle($request, fn () => new Response);
        } catch (Throwable) {
            // the page falls back to the app's default language
        }
    }

    private static function signedIn(Request $request): bool
    {
        try {
            return $request->user() !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
