<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ForcedPasswordChangeController extends Controller
{
    public function show(): View
    {
        return view('auth.force-password-change');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $user = $request->user();
        abort_unless($user !== null && $user->must_change_password, 404);

        $user->password = $validated['password'];
        $user->must_change_password = false;
        $user->save();

        $request->session()->regenerate();

        return redirect()
            ->route('home')
            ->with('success', 'Your password has been updated.');
    }
}
