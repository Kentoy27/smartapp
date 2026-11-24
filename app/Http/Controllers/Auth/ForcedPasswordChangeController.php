<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDeviceSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ForcedPasswordChangeController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        abort_unless($user instanceof User, 404);

        if (!$user->requiresPasswordChange()) {
            return redirect()->route('home');
        }

        return view('auth.password-change', [
            'user' => $user,
            'canUseGoogleSignIn' => (bool) $request->session()->get('password_change_google_authenticated', false),
            'canUseGoogleSignIn' => $request->session()->get('password_change_google_authenticated', false),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->requiresPasswordChange(), 404);

        $googleAuthenticated = (bool) $request->session()->get('password_change_google_authenticated', false);
        $googleAuthenticated = $request->session()->get('password_change_google_authenticated', false);
        $validated = $request->validate([
            'current_password' => $googleAuthenticated
                ? ['nullable', 'string', 'current_password']
                : ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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
