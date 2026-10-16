<?php

namespace App\Http\Middleware;

use App\Support\License;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a route the install's tier does not include: `feature:returns`.
 *
 * Hiding a nav link is cosmetic; this is the part that actually holds, so a
 * Starter till typing /suppliers into the address bar gets turned away rather
 * than served. Shaped like DemoGate on purpose — same refusal shape, same
 * JSON-vs-redirect split.
 */
class FeatureGate
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (License::allows($feature)) {
            return $next($request);
        }

        $message = __('plan.feature_locked');

        if ($request->expectsJson()) {
            return response()->json(['error' => $message], 403);
        }

        // Nothing links here on a tier that lacks the feature, so going back
        // lands wherever they came from. A URL typed straight into the address
        // bar has no previous page but its own — bouncing them there would
        // loop — so that case falls through to a page every role can open.
        $back = url()->previous();
        $target = ($back === '' || $back === $request->fullUrl() || $back === $request->url())
            ? route('dashboard')
            : $back;

        return redirect($target)->withErrors(['plan' => $message]);
    }
}
