<?php

namespace App\Http\Middleware;

use App\Services\MetabaseOrigin;
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

        $origin = MetabaseOrigin::fromConfiguration();

        if ($origin !== null) {
            $frameSources[] = $origin;
        }

        return 'frame-src '.implode(' ', $frameSources).'; '.self::BASE_DIRECTIVES;
    }
}
