<?php

use App\Http\Middleware\CheckVipAccess;
use App\Http\Middleware\MetabaseCspHeaders;
use App\Http\Middleware\SecurityHeaders;
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
        // eksplisit (TRUSTED_PROXIES, daftar dipisah koma). Default kosong: tanpa
        // proxy terpercaya IP pengguna diambil dari REMOTE_ADDR, sehingga tidak
        // bisa dipalsukan lewat header — penting karena rate limit login
        // mengunci berdasarkan IP.
        $trustedProxies = array_filter(
            array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))
        );

        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
