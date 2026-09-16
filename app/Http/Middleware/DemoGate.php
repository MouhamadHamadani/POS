<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two halves of demo gating, in one place:
 *
 *   demo:only     — the route exists only in a demo build (404 otherwise), so a
 *                   production install has no "reset my data" endpoint at all.
 *   demo:blocked  — the route exists, but a demo build refuses it and says why.
 *                   Used for anything with a real-world side effect: backups,
 *                   exports, and the settings a demo must keep fixed.
 */
class DemoGate
{
    private const REFUSAL = 'Not available in the demo build. — غير متاح في النسخة التجريبية.';

    public function handle(Request $request, Closure $next, string $mode = 'only'): Response
    {
        $demo = Demo::enabled();

        if ($mode === 'only' && ! $demo) {
            abort(404);
        }

        if ($mode === 'blocked' && $demo) {
            if ($request->expectsJson()) {
                return response()->json(['error' => self::REFUSAL], 403);
            }

            return back()->withErrors(['demo' => self::REFUSAL]);
        }

        return $next($request);
    }

    /** So controllers refusing a demo action say the same thing this does. */
    public static function refusal(): string
    {
        return self::REFUSAL;
    }
}
