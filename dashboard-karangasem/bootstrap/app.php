<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\CheckVipAccess;
use App\Http\Middleware\MetabaseCspHeaders;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StrictTransportSecurity;
use App\Services\TrustedProxyList;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Percaya pada header X-Forwarded-* hanya dari proxy yang dikonfigurasi
        // eksplisit (TRUSTED_PROXIES, daftar dipisah koma). Default kosong:
        // tanpa proxy terpercaya IP pengguna diambil dari REMOTE_ADDR, sehingga
        // tidak bisa dipalsukan lewat header — penting karena rate limit login
        // mengunci berdasarkan IP dan audit log mencatat IP pelaku.
        //
        // env() (bukan config()) karena closure ini dijalankan sebelum
        // container `config` terikat. Parsing-nya tetap satu sumber
        // kebenaran lewat App\Services\TrustedProxyList.
        $trustedProxies = TrustedProxyList::fromEnvironment();

        if ($trustedProxies !== []) {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'check.vip' => CheckVipAccess::class,
            'metabase.csp' => MetabaseCspHeaders::class,
        ]);

        // Header keamanan global (nosniff, Referrer-Policy, Permissions-Policy,
        // COOP, X-Frame-Options). CSP sengaja tidak dipasang di sini: CSP
        // dikelola per-rute oleh MetabaseCspHeaders agar skrip inline panel
        // Filament tetap berjalan.
        $middleware->append(SecurityHeaders::class);

        // Strict-Transport-Security. Header hanya dikirim untuk request HTTPS
        // dan hanya bila HSTS_ENABLED=true, sehingga domain tidak pernah
        // terkunci ke HTTPS sebelum TLS benar-benar terpasang.
        $middleware->append(StrictTransportSecurity::class);

        // Identitas request untuk korelasi log ↔ respons. Dipasang lebih
        // dulu dari middleware lain agar middleware yang mencatat log punya
        // `request_id` di konteksnya sejak baris pertama.
        $middleware->append(AssignRequestId::class);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Semua jam di bawah ditulis dalam waktu lokal WITA. Tanpa
        // ->timezone() eksplisit, jadwal memakai `config('app.schedule_timezone')`
        // yang default-nya UTC — artinya "00:30" justru berjalan pukul 08:30
        // WITA, dan akun VIP baru dinonaktifkan delapan jam setelah langganan
        // habis. Nilai diambil dari `app.timezone` supaya tetap satu sumber
        // kebenaran dengan panel admin dan dengan `expires_at`.
        $schedule->timezone(config('app.timezone'));

        // Penonaktifan VIP. 00:30 memberi jeda bagi operator yang menyalakan
        // akun secara manual selepas tengah malam, sehingga perpanjangan
        // yang baru dilakukan tidak langsung ditimpa job pada malam sama.
        $schedule->command('vip:deactivate-expired')
            ->dailyAt('00:30')
            ->withoutOverlapping(30)
            ->onOneServer();

        // Audit log dipangkas 03:00 WITA: sesudah penonaktifan VIP (00:30)
        // sehingga entri `user.auto_deactivated` hari itu sudah lahir dan
        // tidak ikut terpotong, dan sesudah backup harian (02:00, lihat
        // deploy/backup/run-backup.sh) sehingga pemangkasan tidak pernah
        // berjalan bersamaan dengan backup yang masih membaca tabel sama.
        $schedule->command('audit:prune --days=365')
            ->dailyAt('03:00')
            ->withoutOverlapping(120)
            ->onOneServer();

        // Sentinel: `metabase:check-embedding` sudah mengembalikan exit code
        // non-nol saat Metabase tidak dapat dihubungi atau membalas 4xx/5xx,
        // jadi jadwal ini menjadikannya sinyal yang bisa dibaca uptime
        // monitor tanpa kode tambahan.
        $schedule->command('metabase:check-embedding')
            ->hourly()
            ->withoutOverlapping(10)
            ->onOneServer();

        $schedule->command('security:scan-pii')
            ->weeklyOn(1, '05:00')
            ->withoutOverlapping(30)
            ->onOneServer();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
