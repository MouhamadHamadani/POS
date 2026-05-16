<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\Shift;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ShiftRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Setting::get('require_shift', true)) {
            return $next($request);
        }

        $user = $request->user();
        if ($user) {
            $hasOpen = Shift::where('user_id', $user->id)
                ->where('status', Shift::STATUS_OPEN)
                ->exists();

            if (!$hasOpen) {
                if ($request->expectsJson()) {
                    return response()->json(['error' => 'No open shift', 'redirect' => '/shifts/open'], 409);
                }
                return redirect('/shifts/open')->with('warning', 'You must open a shift before selling.');
            }
        }

        return $next($request);
    }
}
