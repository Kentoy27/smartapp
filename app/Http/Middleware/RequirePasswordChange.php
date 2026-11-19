<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->requiresPasswordChange()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'You must change your password before using SmartApp.',
                    'password_change_url' => route('password.change'),
                ], 423);
            }

            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
