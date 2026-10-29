<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());
        $request->user()->save();

        return redirect()->route('profile.edit')->with('status', 'profile-updated');
    }

    /** The signed-in user's own interface size. The request names no user, so it cannot reach anyone else's row. */
    public function updateUiScale(Request $request): RedirectResponse
    {
        $data = $request->validate(['ui_scale' => ['required', 'integer', Rule::in(User::UI_SCALES)]]);

        $request->user()->forceFill(['ui_scale' => (int) $data['ui_scale']])->save();

        return back();
    }

    public function updatePin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'pin' => ['required', 'string', 'digits:4'],
        ]);

        $request->user()->update(['pin' => $data['pin']]); // model cast = hashed

        return redirect()->route('profile.edit')->with('status', 'pin-updated');
    }
}
