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
                    return response()->json(['error' => __('messages.no_open_shift_short'), 'redirect' => '/shifts/open'], 409);
                }
                return redirect('/shifts/open')->with('warning', __('messages.shift_open_first'));
            }
        }

        return $next($request);
    }
}
