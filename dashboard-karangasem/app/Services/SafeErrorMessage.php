<?php

namespace App\Services;

use Throwable;

/**
 * Sanitasi pesan exception sebelum dicetak oleh perintah Artisan.
 *
 * Pesan error dari Guzzle/curl memuat URL lengkap permintaan. Perintah yang
 * berinteraksi dengan Metabase meminta endpoint yang sensitif — `/embed/
 * dashboard/{token}` dan header `X-Metabase-Session-Id` — sehingga mencetak
 * pesan exception apa adanya bisa membocorkan token ke terminal, log CI, dan
 * log sistem. Bagian yang berguna untuk diagnosis (kode errno, kelas
 * exception, HTTP status) tetap dipertahankan; hanya URL-nya yang dibuang.
 */
class SafeErrorMessage
{
    /**
     * Pola URL yang dibuang dari pesan exception.
     */
    private const URL_PATTERN = '#\b[a-z][a-z0-9+.-]*://\S+#i';

    /**
     * Teks pengganti untuk URL yang dibuang.
     */
    private const REDACTED_URL = '[URL dihapus]';

    /**
     * Pesan yang aman dicetak: URL dibuang, sisanya dipertahankan.
     */
    public static function for(Throwable $e): string
    {
        $message = preg_replace(self::URL_PATTERN, self::REDACTED_URL, $e->getMessage());

        if (! is_string($message) || trim($message) === '') {
            return $e::class;
        }

        return $message;
    }
}
