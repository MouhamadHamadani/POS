<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
