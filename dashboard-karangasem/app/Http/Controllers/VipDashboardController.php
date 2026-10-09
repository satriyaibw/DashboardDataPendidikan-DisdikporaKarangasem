<?php

namespace App\Http\Controllers;

use App\Services\MetabaseEmbedService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class VipDashboardController extends Controller
{
    /**
     * Halaman VIP dengan iframe signed embed Metabase (JWT HS256, TTL pendek).
     */
    public function index(MetabaseEmbedService $metabase): View
    {
        return view('vip.dashboard', [
            'embedUrl' => $metabase->signedUrl(),
            'refreshIntervalSeconds' => $this->refreshIntervalSeconds(),
        ]);
    }

    /**
     * Endpoint JSON untuk memperbarui token embed tanpa reload halaman.
     */
    public function embedUrl(MetabaseEmbedService $metabase): JsonResponse
    {
        return response()
            ->json(['embed_url' => $metabase->signedUrl()])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Interval polling (< TTL) agar iframe selalu memuat token yang masih valid.
     */
    protected function refreshIntervalSeconds(): int
    {
        $ttl = (int) config('metabase.embed_ttl', 600);

        return max(1, (int) floor($ttl * 0.8));
    }
}
