<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    /**
     * Roles the acting user may assign. Two independent narrowings:
     *
     *  - privilege: an `admin` is the client's store owner, so they manage
     *    their own staff but cannot mint another admin-tier account.
     *  - licence:   the install's tier decides which staff roles exist at all
     *    (Starter has no Stock Keeper or Admin). This binds a super_admin too —
     *    the tier describes the install, not the person editing it — but
     *    super_admin itself stays assignable, since it is the vendor's own
     *    account and consumes no seat.
     */
    private function assignableRoles(Request $request): array
    {
        $byPrivilege = array_keys($request->user()->isSuperAdmin() ? User::ROLES : User::STAFF_ROLES);
        $byLicence = [...License::allowedRoles(), User::ROLE_SUPER_ADMIN];

        return array_values(array_intersect($byPrivilege, $byLicence));
    }

    /**
     * A role the tier does not include, submitted anyway. Separate from the
     * Rule::in check so the refusal says "not in your plan" rather than the
     * generic invalid-selection message — and says it in the user's language.
     */
    private function roleLicenceError(Request $request, ?User $target = null): ?string
    {
        $role = (string) $request->input('role');

        // Keeping the role a user already has is not an assignment: after the vendor lowers
        // the plan, the owner's account must still be editable without being moved to
        // another role (BUG-015). Only a role that CHANGES has to be in the plan.
        $blocked = array_key_exists($role, User::ROLES)
            && $role !== User::ROLE_SUPER_ADMIN
            && $role !== $target?->role
            && ! in_array($role, License::allowedRoles(), true);

        return $blocked ? __('plan.role_locked') : null;
    }

    /**
     * Seats are counted per install, not per actor, so this refuses a
     * super_admin's request too. Enforced here as well as in the form so a
     * crafted POST cannot walk past it.
     */
    private function seatLimitError(string $role): ?string
    {
        if ($role === User::ROLE_SUPER_ADMIN || ! License::isAtUserLimit()) {
            return null;
        }

        return __('plan.user_limit', ['used' => License::seatsUsed(), 'max' => License::maxUsers()]);
    }

    /**
     * Admin-tier accounts may only be touched by a super_admin. Called from
     * every write path that takes an existing user so a new one can't miss it.
     */
    private function guardPrivilegedTarget(Request $request, User $user): void
    {
        abort_if(
            $user->isPrivileged() && !$request->user()->isSuperAdmin(),
            403,
            __('messages.only_super_admin')
        );
    }

    public function index(Request $request): View
    {
        $q = User::query();

        if ($s = $request->query('search')) {
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%{$s}%")
                  ->orWhere('username', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%");
            });
        }

        if ($role = $request->query('role')) {
            $q->where('role', $role);
        }

        $users = $q->orderBy('is_active', 'desc')->orderBy('name')->paginate(25)->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create(): View
    {
        return view('users.form', [
            'user' => new User(['role' => 'cashier', 'language' => Setting::get('language', 'en'), 'is_active' => true]),
            'isEdit' => false,
            'seatLimit' => $this->seatLimitError(User::ROLE_CASHIER),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($error = $this->roleLicenceError($request)) {
            return back()->withInput()->withErrors(['role' => $error]);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:60', Rule::unique('users', 'username')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => 'required|string|min:6|confirmed',
            'role' => ['required', Rule::in($this->assignableRoles($request))],
            'language' => 'required|in:en,ar',
            'max_discount_pct' => 'nullable|numeric|min:0|max:100',
            'pin' => 'nullable|digits:4',
            'is_active' => 'nullable|boolean',
        ]);

        if ($error = $this->seatLimitError($data['role'])) {
            return back()->withInput()->withErrors(['role' => $error]);
        }

        $data['is_active'] = $request->boolean('is_active');
        $data['max_discount_pct'] ??= 0;

        $user = User::create($data);

        AuditLog::record($request->user()->id, 'create', User::class, $user->id, null, ['username' => $user->username, 'role' => $user->role]);

        return redirect()->route('users.index')->with('success', __('messages.user_created', ['username' => $user->username]));
    }

    public function edit(Request $request, User $user): View
    {
        // An admin manages staff only; their own details live under /profile.
        $this->guardPrivilegedTarget($request, $user);

        return view('users.form', ['user' => $user, 'isEdit' => true, 'seatLimit' => null]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->guardPrivilegedTarget($request, $user);

        if ($error = $this->roleLicenceError($request, $user)) {
            return back()->withInput()->withErrors(['role' => $error]);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:60', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            // The target's own current role stays valid (it is not being assigned); the editor's
            // privilege still bounds every other value, and guardPrivilegedTarget() above
            // already keeps a non-super_admin away from admin-tier targets.
            'role' => ['required', Rule::in([...$this->assignableRoles($request), $user->role])],
            'language' => 'required|in:en,ar',
            'max_discount_pct' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        // Prevent an admin-tier user from demoting / deactivating themselves into a lockout
        if ($user->id === $request->user()->id) {
            $staysPrivileged = in_array($data['role'], [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN], true);
            if (!$staysPrivileged || !$request->boolean('is_active')) {
                return back()->withErrors(['role' => __('messages.cannot_demote_self')]);
            }
        }

        $data['is_active'] = $request->boolean('is_active');
        $data['max_discount_pct'] ??= 0;

        $old = $user->only(array_keys($data));
        $user->update($data);

        AuditLog::record($request->user()->id, 'update', User::class, $user->id, $old, $data);

        return redirect()->route('users.index')->with('success', __('messages.user_updated', ['username' => $user->username]));
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->guardPrivilegedTarget($request, $user);

        $data = $request->validate([
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user->update(['password' => Hash::make($data['password'])]);

        AuditLog::record($request->user()->id, 'reset_password', User::class, $user->id, null, ['target' => $user->username]);

        return back()->with('success', __('messages.password_reset', ['username' => $user->username]));
    }

    public function resetPin(Request $request, User $user): RedirectResponse
    {
        $this->guardPrivilegedTarget($request, $user);

        $data = $request->validate(['pin' => 'required|digits:4']);
        $user->update(['pin' => $data['pin']]); // hashed via cast
        AuditLog::record($request->user()->id, 'reset_pin', User::class, $user->id);
        return back()->with('success', __('messages.pin_reset', ['username' => $user->username]));
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $this->guardPrivilegedTarget($request, $user);

        if ($user->id === $request->user()->id) {
            return back()->withErrors(['active' => __('messages.cannot_deactivate_self')]);
        }
        $adminTier = [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN];
        if ($user->isPrivileged() && $user->is_active
            && User::whereIn('role', $adminTier)->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['active' => __('messages.need_one_admin')]);
        }
        $user->update(['is_active' => !$user->is_active]);
        return back()->with('success', $user->is_active ? __('messages.user_activated', ['username' => $user->username]) : __('messages.user_deactivated', ['username' => $user->username]));
    }
}
