<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * One-time first-run provisioning.
 *
 * A shipped build carries no accounts, so nothing secret is extractable from
 * the installer. Whoever sets the machine up creates the owner account here,
 * on the machine, and the route closes behind them the moment a user exists.
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
        ]);

        $user = User::create($data + [
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
            'max_discount_pct' => 100,
            'email_verified_at' => now(),
        ]);

        // No authenticated actor exists yet, so the owner is their own actor.
        AuditLog::record($user->id, 'setup', User::class, $user->id, null, [
            'username' => $user->username,
            'role' => $user->role,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')
            ->with('success', "Owner account '{$user->username}' created. This setup screen is now closed.");
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
