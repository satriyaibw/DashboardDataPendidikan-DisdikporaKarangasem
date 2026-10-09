<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StrictTransportSecurity
{
    /**
     * Masa berlaku (detik) header Strict-Transport-Security. Dua tahun adalah
     * nilai minimum yang diminta oleh daftar preload HSTS.
     */
    private const DEFAULT_MAX_AGE = 31536000;

    /**
     * Pasang Strict-Transport-Security hanya pada request yang benar-benar
     * aman (HTTPS) dan ketika pemaksaan HTTPS dinyalakan.
     *
     * Header TIDAK dikirim pada HTTP biasa: mengirimnya sejak awal akan
     * membuat browser "mengunci" domain ke HTTPS sebelum TLS benar-benar
     * terpasang, dan pencabutannya jauh lebih lambat daripada pematiannya.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Strict-Transport-Security
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldSendHsts($request)) {
            return $response;
        }

        $response->headers->set('Strict-Transport-Security', $this->strictTransportSecurity());

        return $response;
    }

    /**
     * HSTS hanya sah untuk request yang sudah aman; koneksi HTTP polos
     * mengabaikan header ini dan mengirimnya justru berisiko bila domain
     * belum siap HTTPS.
     */
    protected function shouldSendHsts(Request $request): bool
    {
        return $request->isSecure() && (bool) config('app.hsts_enabled');
    }

    /**
     * Nilai header, mis. `max-age=31536000; includeSubDomains`.
     *
     * `includeSubDomains` memakai max-age positif. Hanya sertakan bila
     * benar-benar semua subdomain siap HTTPS, atau hilangkan jika belum.
     */
    protected function strictTransportSecurity(): string
    {
        $maxAge = max(0, (int) config('app.hsts_max_age', self::DEFAULT_MAX_AGE));

        return "max-age={$maxAge}; includeSubDomains";
    }
}
