<?php

namespace App\Http\Controllers;

use App\Support\Activation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivationController extends Controller
{
    /** The lock screen. Not locked? Nothing to show — send them home. */
    public function locked(): View|RedirectResponse
    {
        return Activation::isLocked() ? view('activation.locked') : redirect('/');
    }

    /**
     * Unlock permanently. Behind the same `role:super_admin` group as backups
     * and permissions (routes/web.php), and it re-asks for the signed-in
     * super_admin's password: this is the one action that releases the licence,
     * and a vendor session left open on a client's till should not be enough.
     */
    public function activate(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        Activation::activate($request->user());

        return redirect()->route('settings.index', ['tab' => 'plan'])
            ->with('success', __('activation.activated'));
    }

    /**
     * Lock the install now. Same route group and same password re-check as
     * activate(), plus a ticked box: this one shuts the client out immediately.
     * The super_admin stays able to sign in and reach the Plan tab (ActivationGate).
     */
    public function deactivate(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => __('activation.confirm_required')]);

        Activation::deactivate($request->user());

        return redirect()->route('settings.index', ['tab' => 'plan'])
            ->with('success', __('activation.deactivated'));
    }
}
