<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Demo-build-only actions. The route is behind `demo:only`, so this controller
 * is unreachable in a production install.
 */
class DemoController extends Controller
{
    /**
     * Restore the seeded baseline without restarting the app — for the second
     * meeting of the day, or a demo that has been shopped into a mess.
     *
     * The database is replaced wholesale underneath us, so the session that
     * fired this no longer means anything: log out and hand back a clean login.
     */
    public function reset(Request $request): RedirectResponse
    {
        $username = $request->user()->username;

        try {
            Demo::resetFromTemplate();
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['demo' => 'Demo reset failed: '.$e->getMessage()]);
        }

        // Recorded into the restored database, so the actor is looked up there —
        // the id from the old one no longer refers to anybody.
        if ($actor = User::where('username', $username)->value('id')) {
            AuditLog::record($actor, 'demo_reset', null, null, null, ['source' => 'demo-template']);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('status', 'Demo data reset. Everything is back to the seeded catalogue.');
    }
}
