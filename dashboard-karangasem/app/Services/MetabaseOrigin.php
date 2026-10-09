<?php

namespace App\Services;

/**
 * Menentukan origin Metabase yang dapat dipercaya dari `metabase.site_url`.
 *
 * Nilai yang tidak dapat dipercaya (skema selain http/https, tanpa host,
 * memuat kredensial) mengembalikan null supaya pemanggil mengganti
 * kebijakan menjadi fail-closed alih-alih menebak.
 */
class MetabaseOrigin
{
    /**
     * Skema URL Metabase yang diizinkan.
     *
     * @var array<int, string>
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Origin (skema + host + port non-default) dari URL Metabase, atau null
     * bila URL tidak dapat dipercaya.
     */
    public static function resolve(?string $siteUrl): ?string
    {
        $siteUrl = trim((string) $siteUrl);

        if ($siteUrl === '') {
            return null;
        }

        $parts = parse_url($siteUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        $origin = $scheme.'://'.$host;

        if (isset($parts['port']) && ! self::isDefaultPort($scheme, (int) $parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    /**
     * Origin Metabase dari konfigurasi aplikasi.
     */
    public static function fromConfiguration(): ?string
    {
        return self::resolve((string) config('metabase.site_url'));
    }

    /**
     * Port default untuk skema tertentu, sehingga tidak ikut ditulis.
     */
    protected static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 443);
    }
}
