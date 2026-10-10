<?php

namespace App\Services;

/**
 * Daftar proxy tepercaya dari variabel lingkungan `TRUSTED_PROXIES`.
 *
 * Terpisah sebagai kelas sendiri karena `bootstrap/app.php` membacanya
 * sebelum container `config` terikat, sementara test membacanya setelahnya.
 * Satu tempat parsing membuat kedua jalur tidak mungkin berbeda.
 */
class TrustedProxyList
{
    /**
     * Nama variabel lingkungan yang dibaca.
     */
    public const ENVIRONMENT_KEY = 'TRUSTED_PROXIES';

    /**
     * Daftar alamat proxy, tanpa entri kosong.
     *
     * Daftar kosong berarti tidak ada proxy tepercaya: IP diambil dari
     * REMOTE_ADDR sehingga `X-Forwarded-For` dari klien mana pun tidak
     * dapat dipalsukan.
     *
     * @return array<int, string>
     */
    public static function fromEnvironment(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) env(self::ENVIRONMENT_KEY, '')))
        ));
    }
}
