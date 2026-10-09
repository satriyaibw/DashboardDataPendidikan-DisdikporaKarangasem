<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

/**
 * Membangun URL embedding Metabase: public embed (tanpa token) untuk dashboard
 * publik dan signed embed (JWT HS256, TTL pendek) untuk dashboard VIP.
 *
 * Secret hanya dibaca dari konfigurasi (env) dan token tidak pernah dicatat ke log.
 */
class MetabaseEmbedService
{
    /**
     * URL public embed dashboard publik — tanpa token, aman untuk anonim.
     */
    public function publicUrl(): string
    {
        return $this->siteUrl().'/public/dashboard/'.$this->publicDashboardId();
    }

    /**
     * URL signed embed dashboard VIP — JWT HS256 berisi resource, params, dan exp.
     *
     * @param  array<string, mixed>  $params  Parameter terfilter dashboard Metabase
     */
    public function signedUrl(array $params = []): string
    {
        $payload = [
            'resource' => ['dashboard' => $this->vipDashboardId()],
            'params' => (object) $params,
            'exp' => time() + (int) config('metabase.embed_ttl', 600),
        ];

        $token = JWT::encode($payload, $this->embeddingSecret(), 'HS256');

        return $this->siteUrl().'/embed/dashboard/'.$token.'#bordered=true&titled=true';
    }

    /**
     * Decode token signed embed. Hanya untuk pengujian; produksi tidak pernah decode.
     */
    public function decodeToken(string $token): object
    {
        return JWT::decode($token, new Key($this->embeddingSecret(), 'HS256'));
    }

    /**
     * Base URL Metabase tanpa trailing slash.
     */
    protected function siteUrl(): string
    {
        $siteUrl = rtrim((string) config('metabase.site_url'), '/');

        if ($siteUrl === '') {
            throw new RuntimeException('Metabase site URL is not configured.');
        }

        return $siteUrl;
    }

    protected function embeddingSecret(): string
    {
        $secret = (string) config('metabase.embedding_secret');

        if ($secret === '') {
            throw new RuntimeException('Metabase embedding secret is not configured.');
        }

        return $secret;
    }

    protected function publicDashboardId(): string
    {
        $id = (string) config('metabase.public_dashboard_id');

        if ($id === '') {
            throw new RuntimeException('Metabase public dashboard ID is not configured.');
        }

        return $id;
    }

    protected function vipDashboardId(): int
    {
        $id = (int) config('metabase.vip_dashboard_id');

        if ($id <= 0) {
            throw new RuntimeException('Metabase VIP dashboard ID is not configured.');
        }

        return $id;
    }
}
