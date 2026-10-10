<?php

namespace App\Providers;

use App\Listeners\DiagnoseApplicationHealth;
use App\Observers\RoleAssignmentObserver;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

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

        $this->registerRoleAssignmentAudit();

        $this->warnWhenScheduleTimezoneIsNotConfigured();
    }

    /**
     * Catat perubahan peran ke jejak audit.
     *
     * Diaktifkan lewat `permission.events_enabled` karena Spatie hanya
     * memancarkan event peran bila sakelar tersebut aktif. Tanpanya, kolom
     * `roles` pada audit `user.created` selalu kosong: Spatie menunda
     * `assignRole()` sampai event `saved`, jadi saat `created` dipanggil
     * pivot peran belum ada.
     */
    protected function registerRoleAssignmentAudit(): void
    {
        config(['permission.events_enabled' => true]);

        Event::listen(RoleAttachedEvent::class, [RoleAssignmentObserver::class, 'roleAttached']);
        Event::listen(RoleDetachedEvent::class, [RoleAssignmentObserver::class, 'roleDetached']);
    }

    /**
     * Peringatkan saat zona waktu aplikasi masih bawaan UTC.
     *
     * `config('app.timezone')` memakai `env('APP_TIMEZONE', 'UTC')`, jadi
     * `.env` yang lupa diisi tidak menghasilkan error apa pun — semua
     * jadwal tetap berjalan, hanya pukul 08:30 WITA untuk "00:30", sehingga
     * akun VIP baru dinonaktifkan delapan jam setelah langganannya habis.
     *
     * Hanya peringatan, bukan exception: timezone UTC tetap sah untuk
     * instalasi lain, dan menggagalkan boot karena nilai yang sebenarnya
     * tidak salah hanya akan menutupi masalah lain yang lebih serius.
     */

    /**
     * Peringatkan saat zona waktu aplikasi masih bawaan UTC.
     */
    protected function warnWhenScheduleTimezoneIsNotConfigured(): void
    {
        if (config('app.timezone') !== 'UTC') {
            return;
        }

        Log::warning('APP_TIMEZONE belum diisi; seluruh jadwal memakai UTC, bukan waktu lokal.', [
            'expected' => 'Asia/Makassar',
            'impact' => 'Penonaktifan VIP dan audit:prune berjalan 8 jam lebih lambat dari jadwal yang dimaksud.',
        ]);
    }
}
