<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Paksa skema https pada seluruh URL yang dihasilkan aplikasi. Hanya
        // aktif di produksi (FORCE_HTTPS=true) agar pengembangan lokal dengan
        // http://localhost tetap berjalan.
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }
    }
}
