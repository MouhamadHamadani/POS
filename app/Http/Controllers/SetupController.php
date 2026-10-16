<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Activation;
use App\Support\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * One-time first-run provisioning.
 *
 * A shipped build carries no accounts, so nothing secret is extractable from
 * the installer. Whoever sets the machine up — the vendor — creates the
 * `super_admin` account here, on the machine, and the route closes behind them
 * the moment a user exists. The client's own `admin` is a separate account,
 * created afterwards from Users → New User (see INSTALL_NOTES.md §4); it is
 * never made here, so the vendor's credentials are never the client's.
 */
class SetupController extends Controller
{
    public function show(): View
    {
        $this->abortIfProvisioned();

        return view('setup');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->abortIfProvisioned();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:60', Rule::unique('users', 'username')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => 'required|string|min:8|confirmed',
            'pin' => 'nullable|digits:4',
            'language' => 'required|in:en,ar',
            // Skippable, not required: a setup that posts without a tier lands on
            // Starter. Under-provisioning shows up as a missing feature someone
            // rings about; over-provisioning would quietly hand out the lot.
            'tier' => ['nullable', Rule::in(License::tiers())],
        ]);

        $tier = $data['tier'] ?? 'starter';
        unset($data['tier']);

        $user = User::create($data + [
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
            'max_discount_pct' => 100,
            'email_verified_at' => now(),
        ]);

        // The language picked here is the store's default too: it is what the
        // login screen and every new account start in.
        Setting::set('language', $user->language, 'appearance');

        // The 14-day trial clock starts when setup finishes, not at first launch:
        // the installer may stage a machine days before the client sits down.
        Activation::startTrial();

        // No authenticated actor exists yet, so the new account is its own actor.
        AuditLog::record($user->id, 'setup', User::class, $user->id, null, [
            'username' => $user->username,
            'role' => $user->role,
            'activation_status' => Activation::status(),
        ]);

        // Set before login so the very first authenticated request already sees
        // the right tier. Audited against the owner for the same reason the
        // account creation above is.
        License::setTier($tier, $user->id);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')
            ->with('success', __('messages.setup_done', ['username' => $user->username]));
    }

    /**
     * Setup is strictly first-run. Once any account exists this route is gone,
     * so it can never be used to mint a second super_admin — that is what User
     * Management, behind the role gate, is for.
     */
    private function abortIfProvisioned(): void
    {
        abort_if(User::query()->exists(), 404);
    }
}
