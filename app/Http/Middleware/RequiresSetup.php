<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A shipped build contains no user accounts — the owner account is created on
 * the machine itself, once, through the setup screen. Until that has happened
 * every route funnels to /setup, because there is no account to log in with and
 * a login form would just be a dead end.
 *
 * Guarding here rather than on `/` and `/login` individually means a deep link
 * (an Electron window restoring its last URL, a bookmark) can't slip past into
 * an app with no users.
 */
class RequiresSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        // ponytail: an unindexed-but-tiny EXISTS on local SQLite, run per
        // request. Cache it if it ever shows up in a profile — but a stale
        // "users exist" flag would lock the owner out of setup, so it needs a
        // real invalidation story before it's worth the risk.
        if (! $request->is('setup') && ! User::query()->exists()) {
            return redirect()->route('setup.show');
        }

        return $next($request);
    }
}
