<?php

namespace App\Providers;

use App\Listeners\DiagnoseApplicationHealth;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
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

        // Route `/up` bawaan membungkus pemanggilannya dalam try/catch dan
        // membalas 500 HANYA bila listener melempar exception; tidak ada
        // objek hasil yang dikembalikan ke route. Listener karena itu
        // memeriksa database, cache, dan disk, lalu melempar bila salah satu
        // tidak bisa dipakai.
        Event::listen(DiagnosingHealth::class, DiagnoseApplicationHealth::class);
    }
}
