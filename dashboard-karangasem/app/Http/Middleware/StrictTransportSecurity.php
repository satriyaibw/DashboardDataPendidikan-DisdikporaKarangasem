<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StrictTransportSecurity
{
    /**
     * Masa berlaku (detik) header Strict-Transport-Security bila tidak
     * dikonfigurasi. Satu tahun adalah nilai yang lazim dan jauh di atas
     * ambang minimum 180 hari yang diminta browser.
     */
    private const DEFAULT_MAX_AGE = 31536000;

    /**
     * Pasang Strict-Transport-Security hanya pada request yang benar-benar
     * aman (HTTPS) dan ketika HSTS dinyalakan.
     *
     * Header TIDAK dikirim pada HTTP biasa: mengirimnya sejak awal akan
     * membuat browser mengunci domain ke HTTPS sebelum TLS benar-benar
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
     * `includeSubDomains` bersifat opt-in lewat `app.hsts_include_subdomains`
     * dan sengaja tidak mengikuti max-age: direktif itu memaksa seluruh
     * subdomain memakai HTTPS, sehingga tidak boleh ikut aktif hanya karena
     * max-age besar. Saat max-age=0 (masa pencabutan) direktif itu juga
     * dihilangkan karena max-age=0 sudah cukup untuk melepas penguncian,
     * dan `includeSubDomains` tidak menambah nilai apa pun saat itu.
     */
    protected function strictTransportSecurity(): string
    {
        $maxAge = max(0, (int) config('app.hsts_max_age', self::DEFAULT_MAX_AGE));

        $directives = ['max-age='.$maxAge];

        if ($maxAge > 0 && config('app.hsts_include_subdomains', false)) {
            $directives[] = 'includeSubDomains';
        }

        return implode('; ', $directives);
    }
}
