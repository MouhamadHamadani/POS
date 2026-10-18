<?php

namespace App\Http\Middleware;

use App\Support\Activation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Once the 14-day trial has lapsed, or the vendor has locked the install by hand
 * (see App\Support\Activation), every route funnels to the lock screen. Runs in the `web` group for the same reason
 * RequiresSetup does: a deep link, a restored Electron window or a bookmark
 * must not slip past by naming a route nobody thought to guard.
 *
 * Only a super_admin can still do anything: the Plan tab and its activate
 * action. Nothing is deleted or hidden at the data level — the block is purely
 * at the door, so the instant the vendor activates, the till is exactly as the
 * client left it.
 */
class ActivationGate
{
    public function handle(Request $request, Closure $next): Response
    {
        Activation::evaluate();

        if (! Activation::isLocked() || $this->exempt($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['error' => __('activation.lock_title')], 423);
        }

        return $request->user()?->isSuperAdmin()
            ? redirect()->route('settings.index', ['tab' => 'plan'])
            : redirect()->route('locked');
    }

    private function exempt(Request $request): bool
    {
        // Enough to read the lock screen, switch its language, and sign in or
        // out (the vendor has to be able to sign in to unlock).
        if ($request->is('locked', 'login', 'logout', 'locale/*')) {
            return true;
        }

        // super_admin: the Plan tab and the forms on it (activate, deactivate, and
        // set the tier) — not settings.update, backups or anything else. Deactivate
        // is here so a vendor can never lock themselves out of the way back.
        return (bool) $request->user()?->isSuperAdmin()
            && $request->routeIs(
                'settings.index',
                'settings.activation.activate',
                'settings.activation.deactivate',
                'settings.plan.update',
            );
    }
}
