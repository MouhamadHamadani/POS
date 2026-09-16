<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // super_admin is a superset of every other role: it passes any route
        // that lists roles at all, so a future route can't accidentally lock
        // the vendor/owner account out. Super-admin-only routes still gate
        // correctly because they list 'super_admin' and nothing else.
        $allowed = $user
            && ($user->role === User::ROLE_SUPER_ADMIN || in_array($user->role, $roles, true));

        if (!$allowed) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            abort(403);
        }

        return $next($request);
    }
}
