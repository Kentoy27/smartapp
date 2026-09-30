<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $loginInput = trim((string) $request->input('login'));
        $rememberMe = $request->boolean('remember');
        $loginField = filter_var($loginInput, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $credentials = [
            $loginField => $loginInput,
            'password' => $request->input('password'),
        ];

        if (!Auth::attempt($credentials, $rememberMe)) {
            $user = User::where($loginField, $loginInput)->first();

            if ($user && !Hash::check((string) $request->input('password'), $user->password)) {
                throw ValidationException::withMessages([
                    'password' => ['Your password is wrong.'],
                ]);
            }

            throw ValidationException::withMessages([
                'login' => ['The provided credentials do not match our records.'],
            ]);
        }

        if ($rememberMe) {
            cookie()->queue(cookie('smartapp_login', $loginInput, 60 * 24 * 30));
        } else {
            cookie()->queue(cookie()->forget('smartapp_login'));
        }

        $request->session()->regenerate();

        return redirect()
            ->intended('/home')
            ->with('success', 'Login successful. Welcome back!');
    }

    public function logout(Request $request)
    {
        Auth::logout();

        cookie()->queue(cookie()->forget('smartapp_login'));

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('success', 'Logout successful. See you again soon!');
    }
}
