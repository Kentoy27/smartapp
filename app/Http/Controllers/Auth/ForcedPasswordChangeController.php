<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDeviceSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ForcedPasswordChangeController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        abort_unless($user instanceof User, 404);

        if (! $user->must_change_password) {
            return redirect()->route('home');
        }

        return view('auth.password-change', [
            'canUseGoogleSignIn' => $request->session()->boolean('password_change_google_authenticated'),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->must_change_password, 404);

        $googleAuthenticated = $request->session()->boolean('password_change_google_authenticated');
        $validated = $request->validate([
            'current_password' => $googleAuthenticated
                ? ['nullable', 'string', 'current_password']
                : ['required', 'string', 'current_password'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
            'remember_token' => Str::random(60),
        ])->save();

        UserDeviceSession::endAllSessions($user);
        $request->session()->regenerate();
        $request->session()->forget('password_change_google_authenticated');
        $request->session()->put(
            'auth_password_fingerprint',
            hash('sha256', $user->getAuthPassword()),
        );

        return redirect()
            ->route('home')
            ->with('success', 'Your password has been updated.')
            ->with('success_title', 'Password updated');
    }
}
