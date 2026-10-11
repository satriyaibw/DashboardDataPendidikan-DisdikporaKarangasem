<?php

namespace App\Services;

use Firebase\JWT\JWT;
use InvalidArgumentException;
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
     * Skema URL Metabase yang diizinkan. Selain ini ditolak agar nilai env yang
     * salah (mis. "javascript:" atau "data:") tidak pernah masuk ke src iframe.
     *
     * @var array<int, string>
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * URL public embedding dashboard publik — tanpa token, aman untuk anonim.
     *
     * Public embed memakai UUID dashboard, bukan ID numerik. Metabase tidak
     * memetakan ID ke UUID pada endpoint `/public/dashboard/{id}` sehingga ID
     * numerik membuat iframe menampilkan halaman "Unauthenticated".
     */
    public function publicUrl(): string
    {
        return $this->siteUrl().'/public/dashboard/'.$this->publicDashboardUuid();
    }

    /**
     * URL signed embedding dashboard VIP — JWT HS256 berisi resource, params, dan exp.
     *
     * @param  array<string, mixed>  $params  Parameter terfilter dashboard Metabase
     */
    public function signedUrl(array $params = []): string
    {
        $payload = [
            'resource' => ['dashboard' => $this->vipDashboardId()],
            'params' => (object) $this->validatedParams($params),
            'exp' => time() + $this->embedTtl(),
        ];

        $token = JWT::encode($payload, $this->embeddingSecret(), 'HS256');

        return $this->siteUrl().'/embed/dashboard/'.$token.'#bordered=true&titled=true';
    }

    /**
     * Base URL Metabase tanpa trailing slash.
     *
     * @throws RuntimeException bila kosong, bukan URL absolut, memuat
     *                          kredensial, atau memakai skema selain http/https.
     */
    protected function siteUrl(): string
    {
        $siteUrl = rtrim((string) config('metabase.site_url'), '/');

        if ($siteUrl === '') {
            throw new RuntimeException('Metabase site URL is not configured.');
        }

        $parts = parse_url($siteUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), self::ALLOWED_SCHEMES, true)) {
            throw new RuntimeException('Metabase site URL must be an absolute http or https URL.');
        }

        if (isset($parts['user'], $parts['pass'])) {
            throw new RuntimeException('Metabase site URL must not contain credentials.');
        }

        return $siteUrl;
    }

    /**
     * Secret embedding; panjangnya divalidasi agar tidak tertukar dengan nilai
     * lain yang lebih pendek (HS256 memerlukan kunci minimal 256 bit).
     *
     * @throws RuntimeException bila kosong atau lebih pendek dari batas minimum
     */
    protected function embeddingSecret(): string
    {
        $secret = (string) config('metabase.embedding_secret');
        $minimumLength = (int) config('metabase.min_secret_length', 32);

        if ($secret === '') {
            throw new RuntimeException('Metabase embedding secret is not configured.');
        }

        if ($minimumLength > 0 && strlen($secret) < $minimumLength) {
            throw new RuntimeException(sprintf(
                'Metabase embedding secret must be at least %d characters long.',
                $minimumLength,
            ));
        }

        return $secret;
    }

    /**
     * UUID dashboard publik untuk public embed.
     *
     * @throws RuntimeException bila UUID tidak dikonfigurasi atau tidak berbentuk UUID v4
     */
    protected function publicDashboardUuid(): string
    {
        $uuid = trim((string) config('metabase.public_dashboard_uuid'));

        if ($uuid === '') {
            throw new RuntimeException('Metabase public dashboard UUID is not configured.');
        }

        // UUID:v4 persis 36 karakter (8-4-4-4-12) huruf heksadesimal. Nilai ini
        // disisipkan ke path URL, jadi bentuknya divalidasi agar string rusak
        // tidak ikut ter-forming ke iframe dan menjadi sumber tebakan path.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) !== 1) {
            throw new RuntimeException('Metabase public dashboard UUID is not a valid UUID.');
        }

        return $uuid;
    }

    protected function vipDashboardId(): int
    {
        return $this->requiredDashboardId('vip_dashboard_id', 'VIP');
    }

    /**
     * TTL token embedding. Sengaja tidak di-clamp: nilai <= 0 menghasilkan token
     * yang langsung kedaluwarsa supaya salah konfigurasi terlihat, bukan
     * diperbaiki diam-diam.
     */
    protected function embedTtl(): int
    {
        return (int) config('metabase.embed_ttl', 600);
    }

    /**
     * @throws RuntimeException bila ID dashboard belum dikonfigurasi
     */
    protected function requiredDashboardId(string $key, string $label): int
    {
        $id = (int) config('metabase.'.$key);

        if ($id <= 0) {
            throw new RuntimeException("Metabase {$label} dashboard ID is not configured.");
        }

        return $id;
    }

    /**
     * Pastikan hanya nilai skalar (atau array berisi skalar) yang masuk ke token
     * sehingga payload JWT selalu dapat diserialisasi dengan aman.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException bila kunci atau nilai tidak didukung
     */
    protected function validatedParams(array $params): array
    {
        foreach ($params as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException('Metabase embed parameter keys must be non-empty strings.');
            }

            if (! $this->isScalarOrScalarList($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Metabase embed parameter "%s" must be a scalar or a list of scalars.',
                    $key,
                ));
            }
        }

        return $params;
    }

    /**
     * @return bool true bila nilai skalar, atau array yang seluruh elemennya skalar
     */
    protected function isScalarOrScalarList(mixed $value): bool
    {
        if (is_scalar($value)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                return false;
            }
        }

        return true;
    }
}
