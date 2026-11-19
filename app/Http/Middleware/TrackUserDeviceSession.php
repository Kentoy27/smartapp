<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserDeviceSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TrackUserDeviceSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User && $request->hasSession()) {
            $sessionId = $request->session()->getId();
            $passwordFingerprint = hash('sha256', $user->getAuthPassword());
            $storedFingerprint = $request->session()->get('auth_password_fingerprint');

            if (
                UserDeviceSession::wasRevoked($user, $sessionId)
                || (is_string($storedFingerprint) && ! hash_equals($storedFingerprint, $passwordFingerprint))
                || ($storedFingerprint === null && $user->requiresPasswordChange())
            ) {
                UserDeviceSession::endCurrentSession($user, $sessionId);
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()
                    ->route('login')
                    ->withErrors(['login' => 'Your session expired because your password was reset. Please sign in again.']);
            }

            if ($storedFingerprint === null) {
                $request->session()->put('auth_password_fingerprint', $passwordFingerprint);
            }

            UserDeviceSession::recordActivity(
                $user,
                $sessionId,
                $request->userAgent(),
            );
        }

        return $next($request);
    }
}
