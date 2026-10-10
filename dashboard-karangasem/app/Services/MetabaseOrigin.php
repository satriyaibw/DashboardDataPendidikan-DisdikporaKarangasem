<?php

namespace App\Services;

/**
 * Menentukan origin Metabase yang dapat dipercaya dari `metabase.site_url`.
 *
 * Nilai yang tidak dapat dipercaya (skema selain http/https, tanpa host,
 * memuat kredensial, atau host yang bukan hostname/IP valid) mengembalikan
 * null supaya pemanggil mengganti kebijakan menjadi fail-closed alih-alih
 * menebak.
 *
 * Validasi host sengaja ketat: nilai hasil `parse_url()` disisipkan apa
 * adanya ke header CSP `frame-src` maupun `Permissions-Policy`, dan kedua
 * header itu punya sintaks terstruktur. Host berisi `"` atau spasi —
 * misalnya `https://ex"ample.test`, yang tetap lolos `parse_url()` —
 * akan memutus quoted-string pada Permissions-Policy dan membuat seluruh
 * allowlist tidak berlaku. Menolak host tersebut lebih aman daripada
 * bergantung pada escaping pemanggil.
 *
 * Aturan kredensial (userinfo) sengaja sama dengan
 * {@see MetabaseEmbedService::siteUrl()} agar satu URL yang
 * sama tidak dianggap valid oleh satu jalur dan tidak valid oleh jalur lain.
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
     * Hostname (label dipisah titik) yang sah. Menolak tanda kutip, spasi,
     * dan karakter kontrol yang lolos `parse_url()` tetapi merusak header.
     */
    private const HOSTNAME_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9_-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9_-]*[A-Za-z0-9])?)*$/';

    /**
     * Batas panjang hostname (RFC 1035). Di luar ini pastilah salah.
     */
    private const MAX_HOSTNAME_LENGTH = 253;

    /**
     * Panjang label hostname maksimum.
     */
    private const MAX_LABEL_LENGTH = 63;

    /**
     * IP literal versi 6 yang ditulis dalam kurung siku, mis. `[::1]`.
     * Mengizinkan `%zone` untuk IPv6 link-local dengan zone index.
     */
    private const IPV6_LITERAL_PATTERN = '/^\[[0-9A-Fa-f:.%]+]$/';

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

        // URL berkredensial (user:pass@host) ditolak, bukan diam-diam
        // dilepas. Melepas userinfo berarti nilai yang dikonfigurasi berbeda
        // dari nilai yang benar-benar dipakai, dan kredensial bisa bocor ke
        // log maupun iframe bila pemanggil melakukan string concatenation.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = self::normalizeHost($parts['host']);

        if ($host === null) {
            return null;
        }

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

    /**
     * Host dinormalisasi (huruf kecil) dan divalidasi, atau null bila bukan
     * hostname/IP yang sah.
     *
     * Host dari `parse_url()` tidak dijamin aman: `parse_url('https://ex"ample.test')`
     * berhasil dan mengembalikan host berisi tanda kutip.
     */
    protected static function normalizeHost(string $host): ?string
    {
        $host = strtolower($host);

        if ($host === '' || strlen($host) > self::MAX_HOSTNAME_LENGTH) {
            return null;
        }

        if (str_starts_with($host, '[')) {
            return preg_match(self::IPV6_LITERAL_PATTERN, $host) === 1 ? $host : null;
        }

        if (preg_match(self::HOSTNAME_PATTERN, $host) !== 1) {
            return null;
        }

        foreach (explode('.', $host) as $label) {
            if (strlen($label) > self::MAX_LABEL_LENGTH) {
                return null;
            }
        }

        return $host;
    }
}
