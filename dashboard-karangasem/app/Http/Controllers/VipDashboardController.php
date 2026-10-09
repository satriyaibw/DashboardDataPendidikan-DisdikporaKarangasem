<?php

namespace App\Http\Controllers;

use App\Services\MetabaseEmbedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class VipDashboardController extends Controller
{
    /**
     * Halaman VIP dengan iframe signed embed Metabase (JWT HS256, TTL pendek).
     *
     * Header no-store wajib: halaman ini membawa token pada atribut src iframe,
     * sehingga tidak boleh tersimpan di disk cache maupun back-forward cache.
     */
    public function index(MetabaseEmbedService $metabase): Response
    {
        return response()->view('vip.dashboard', [
            'embedUrl' => $metabase->signedUrl(),
            'embedTtlSeconds' => $this->embedTtlSeconds(),
            'refreshIntervalSeconds' => $this->refreshIntervalSeconds(),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Endpoint JSON untuk memperbarui token embed tanpa reload halaman.
     */
    public function embedUrl(MetabaseEmbedService $metabase): JsonResponse
    {
        return response()
            ->json(['embed_url' => $metabase->signedUrl()])
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }

    /**
     * Interval polling maksimum (< TTL) agar iframe selalu memuat token yang
     * masih valid. Mengembalikan 0 bila TTL tidak masuk akar sehingga view
     * tidak menjadwalkan refresh sama sekali (menghindari loop 1 detik).
     */
    protected function refreshIntervalSeconds(): int
    {
        $ttl = $this->embedTtlSeconds();

        if ($ttl <= 0) {
            return 0;
        }

        return $ttl > 1 ? (int) floor($ttl * 0.8) : 0;
    }

    protected function embedTtlSeconds(): int
    {
        return (int) config('metabase.embed_ttl', 600);
    }
}
