<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Cloudflare sits in front of XAMPP, terminates TLS, and forwards the
        // request over plain HTTP with an X-Forwarded-Proto header. Trust the
        // direct peer so Laravel sees it as HTTPS; otherwise URL generation
        // (Livewire's /update endpoint, auth redirects) falls back to http://
        // and the browser blocks it as mixed content on the HTTPS page.
        $middleware->trustProxies(at: '*');

        // Logging out is safe to repeat and must still work when a long-lived
        // Livewire page has an expired CSRF token.
        $middleware->validateCsrfTokens(except: ['logout']);

        // OPCRF Part access: decides, on the server, whether the signed-in
        // staff member may open the Part they asked for. Registered as an
        // alias so the route reads `->middleware('opcrf.part')` and the
        // parameter the Part number arrives in is declared in one place.
        $middleware->alias([
            'opcrf.part' => \App\Http\Middleware\EnsureOpcrfPartIsOpen::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
