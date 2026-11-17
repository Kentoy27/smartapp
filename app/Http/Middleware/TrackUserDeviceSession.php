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
            UserDeviceSession::recordActivity(
                $user,
                $request->session()->getId(),
                $request->userAgent(),
            );
        }

        return $next($request);
    }
}
