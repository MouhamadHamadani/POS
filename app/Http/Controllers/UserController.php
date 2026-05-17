<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
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
            'user' => new User(['role' => 'cashier', 'language' => 'en', 'is_active' => true]),
            'isEdit' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:60', Rule::unique('users', 'username')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => 'required|string|min:6|confirmed',
            'role' => 'required|in:admin,manager,cashier,stock',
            'language' => 'required|in:en,ar',
            'max_discount_pct' => 'nullable|numeric|min:0|max:100',
            'pin' => 'nullable|digits:4',
            'is_active' => 'nullable|boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['max_discount_pct'] ??= 0;

        $user = User::create($data);

        AuditLog::record($request->user()->id, 'create', User::class, $user->id, null, ['username' => $user->username, 'role' => $user->role]);

        return redirect()->route('users.index')->with('success', "Created user '{$user->username}'.");
    }

    public function edit(User $user): View
    {
        return view('users.form', ['user' => $user, 'isEdit' => true]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:60', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => 'required|in:admin,manager,cashier,stock',
            'language' => 'required|in:en,ar',
            'max_discount_pct' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        // Prevent the last admin from demoting / deactivating themselves into a lockout
        if ($user->id === $request->user()->id) {
            if ($data['role'] !== User::ROLE_ADMIN || !$request->boolean('is_active')) {
                return back()->withErrors(['role' => 'You cannot demote or deactivate your own admin account.']);
            }
        }

        $data['is_active'] = $request->boolean('is_active');
        $data['max_discount_pct'] ??= 0;

        $old = $user->only(array_keys($data));
        $user->update($data);

        AuditLog::record($request->user()->id, 'update', User::class, $user->id, $old, $data);

        return redirect()->route('users.index')->with('success', "Updated '{$user->username}'.");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user->update(['password' => Hash::make($data['password'])]);

        AuditLog::record($request->user()->id, 'reset_password', User::class, $user->id, null, ['target' => $user->username]);

        return back()->with('success', "Password reset for '{$user->username}'.");
    }

    public function resetPin(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['pin' => 'required|digits:4']);
        $user->update(['pin' => $data['pin']]); // hashed via cast
        AuditLog::record($request->user()->id, 'reset_pin', User::class, $user->id);
        return back()->with('success', "PIN reset for '{$user->username}'.");
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['active' => 'You cannot deactivate your own account.']);
        }
        if ($user->role === User::ROLE_ADMIN && $user->is_active && User::where('role', 'admin')->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['active' => 'There must be at least one active admin.']);
        }
        $user->update(['is_active' => !$user->is_active]);
        return back()->with('success', $user->is_active ? "Activated '{$user->username}'." : "Deactivated '{$user->username}'.");
    }
}
