<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user !== null
            && $user->must_change_password
            && ! $request->routeIs('password.change', 'password.change.update')
            && ! $request->routeIs('logout')
        ) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
