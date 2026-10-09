<?php

namespace App\Http\Middleware;

use App\Services\MetabaseOrigin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Header defensif yang dipasang pada setiap respons aplikasi.
     *
     * Sengaja TIDAK memuat Content-Security-Policy: CSP hanya dipasang
     * per-rute (lihat MetabaseCspHeaders) karena kebijakan global akan
     * mematikan skrip inline Livewire/Alpine pada panel Filament.
     *
     * @var array<string, string>
     */
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];

    /**
     * Fitur perangkat yang dimatikan untuk halaman aplikasi.
     *
     * @var array<int, string>
     */
    private const DISABLED_FEATURES = [
        'camera',
        'microphone',
        'geolocation',
        'payment',
        'usb',
    ];

    /**
     * Suntikkan header keamanan global tanpa menyentuh CSP per-rute Fase 4.
     *
     * `X-Frame-Options: DENY` hanya dipasang bila respons belum membawa
     * Content-Security-Policy: rute embed sudah mengatur `frame-ancestors`,
     * sehingga menambahkan XFO di sana hanya akan menduplikasi kebijakan
     * framing yang bisa membingungkan saat debug.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $header => $value) {
            $response->headers->set($header, $value);
        }

        $response->headers->set('Permissions-Policy', $this->permissionsPolicy());

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        return $response;
    }

    /**
     * Permissions-Policy aplikasi.
     *
     * `fullscreen` wajib mengizinkan `self` dan origin Metabase: iframe
     * dashboard bersifat cross-origin, dan per Permissions Policy spec akses
     * fitur iframe dipotong oleh kebijakan dokumen induk. Tanpa origin
     * Metabase di allowlist, tombol fullscreen kartu di dalam embed mati.
     */
    protected function permissionsPolicy(): string
    {
        $features = array_map(
            fn (string $feature): string => $feature.'=()',
            self::DISABLED_FEATURES,
        );

        return implode('; ', [...$features, 'fullscreen=('.$this->fullscreenAllowlist().')']);
    }

    /**
     * Daftar origin yang boleh memakai fullscreen. Selalu memuat `self`
     * (tanpa tanda kutip — kata kunci khusus Permissions Policy); origin
     * Metabase dikutip agar tetap valid bila memuat IPv6 atau port.
     */
    protected function fullscreenAllowlist(): string
    {
        $sources = ['self'];

        $origin = MetabaseOrigin::fromConfiguration();

        if ($origin !== null) {
            $sources[] = '"'.$origin.'"';
        }

        return implode(' ', $sources);
    }
}
