<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Send the user to Google's consent screen.
     */
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle Google's callback: log in an existing user by google_id, or
     * link an existing account by matching email. Google accounts that
     * match no user are never registered.
     */
    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            return redirect()
                ->route('login')
                ->withErrors(['login' => 'Google sign-in was cancelled or failed. Please try again.']);
        }

        // 1) Already linked to this Google account
        $user = User::where('google_id', $googleUser->getId())->first();

        // 2) Same verified email, not already owned by another Google
        //    account — link the Google account to it
        if (! $user && $googleUser->getEmail()) {
            $user = User::where('email', $googleUser->getEmail())
                ->whereNull('google_id')
                ->first();

            if ($user) {
                $user->forceFill(['google_id' => $googleUser->getId()])->save();
            }
        }

        // 3) No matching user — never register; send them back with an error
        if (! $user) {
            return redirect()
                ->route('login')
                ->withErrors(['login' => 'No account matches this Google account.']);
        }

        Auth::login($user, remember: true);
        session()->regenerate();

        return redirect()->intended('/home');
    }
}
