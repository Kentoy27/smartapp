<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Global helpers (public_asset etc.) — required here so they exist
        // for every request, console command and test without a composer
        // dump-autoload round-trip.
        require app_path('support/helpers.php');
    }

    public function boot(): void
    {
        //
    }
}
