<?php

if (! function_exists('public_asset')) {
    /**
     * Build a URL to a file inside the app's public/ folder, whichever web
     * server is in front of the app.
     *
     * Blade used to call asset('public/css/app.css'), which 404s under
     * `php artisan serve` (its docroot is already <app>/public, so the URL
     * would resolve to <app>/public/public/...), while the same call is
     * REQUIRED when Apache/XAMPP serves <app> as the docroot. This helper
     * picks the right form per request from the server's DOCUMENT_ROOT:
     *   - artisan serve (docroot ends with /public)  ->  "/css/app.css"
     *   - Apache/XAMPP docroot = <app>               ->  "/public/css/app.css"
     */
    function public_asset(string $path): string
    {
        $path = ltrim($path, '/');

        $docroot = str_replace('\\', '/', rtrim((string) request()->server('DOCUMENT_ROOT')));

        // Servers that don't report a document root get the artisan-serve
        // treatment: that is the setup this app is actively served with.
        $servedFromPublic = $docroot === '' || str_ends_with($docroot, '/public');

        return asset($servedFromPublic ? $path : 'public/' . $path);
    }
}
