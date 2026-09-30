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
        // Logging out is safe to repeat and must still work when a long-lived
        // Livewire page has an expired CSRF token.
        $middleware->validateCsrfTokens(except: ['logout']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
