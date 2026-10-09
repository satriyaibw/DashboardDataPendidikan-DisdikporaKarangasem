<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MetabaseCspHeaders
{
    /**
     * Suntikkan header CSP khusus rute embed agar iframe hanya boleh memuat
     * origin Metabase. Diterapkan per-rute — tidak pernah global — supaya
     * panel Filament (/admin) tidak terdampak.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $origin = $this->metabaseOrigin();

        if ($origin !== null) {
            $response->headers->set(
                'Content-Security-Policy',
                "frame-src 'self' {$origin}",
            );
        }

        return $response;
    }

    /**
     * Origin Metabase (skema + host + port, tanpa path) dari METABASE_SITE_URL.
     */
    protected function metabaseOrigin(): ?string
    {
        $siteUrl = (string) config('metabase.site_url');

        if ($siteUrl === '') {
            return null;
        }

        $parts = parse_url($siteUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
