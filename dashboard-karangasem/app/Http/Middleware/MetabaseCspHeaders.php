<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MetabaseCspHeaders
{
    /**
     * Direktif yang selalu menyertai CSP pada rute embed, apa pun kondisi
     * konfigurasi Metabase.
     */
    private const BASE_DIRECTIVES = "object-src 'none'; base-uri 'self'; frame-ancestors 'none'";

    /**
     * Suntikkan header CSP khusus rute embed agar iframe hanya boleh memuat
     * origin Metabase. Diterapkan per-rute — tidak pernah global — supaya
     * panel Filament (/admin) tidak terdampak.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Selalu ditulis: bila URL Metabase tidak valid, frame-src jatuh ke
        // 'self' (fail-closed) alih-alih halaman embed dibiarkan tanpa CSP.
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());

        return $response;
    }

    /**
     * CSP lengkap untuk rute embed.
     */
    protected function contentSecurityPolicy(): string
    {
        $frameSources = ["'self'"];

        $origin = $this->metabaseOrigin();

        if ($origin !== null) {
            $frameSources[] = $origin;
        }

        return 'frame-src '.implode(' ', $frameSources).'; '.self::BASE_DIRECTIVES;
    }

    /**
     * Origin Metabase (skema + host + port non-default, tanpa path) dari
     * METABASE_SITE_URL. Mengembalikan null bila URL tidak dapat dipercaya.
     */
    protected function metabaseOrigin(): ?string
    {
        $siteUrl = (string) config('metabase.site_url');

        if ($siteUrl === '') {
            return null;
        }

        $parts = parse_url($siteUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']);

        if (isset($parts['port']) && ! $this->isDefaultPort(strtolower($parts['scheme']), (int) $parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    protected function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 443);
    }
}
