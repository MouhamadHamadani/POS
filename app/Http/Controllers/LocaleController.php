<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Guests get a per-session choice; a signed-in user's choice is their saved
     * `language`, because SetLocale reads that first.
     */
    public function switch(Request $request, string $locale): RedirectResponse
    {
        $request->session()->put('locale', $locale);

        $user = $request->user();

        if ($user && $user->language !== $locale) {
            $old = $user->language;
            $user->update(['language' => $locale]);

            AuditLog::record($user->id, 'update', User::class, $user->id, ['language' => $old], ['language' => $locale]);
        }

        return back();
    }
}
