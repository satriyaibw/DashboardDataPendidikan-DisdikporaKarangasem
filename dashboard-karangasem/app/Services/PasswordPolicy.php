<?php

namespace App\Services;

use Illuminate\Validation\Rules\Password;

/**
 * Satu-satunya definisi kebijakan password VIP/admin.
 *
 * Dipakai bersama oleh form Filament (validasi) dan DatabaseSeeder (menolak
 * ADMIN_PASSWORD lemah) supaya definisinya tidak pernah berbeda antara jalur
 * pembuatan akun.
 */
class PasswordPolicy
{
    /**
     * Minimal panjang password.
     */
    public const MINIMUM_LENGTH = 12;

    /**
     * Pesan validasi dalam bahasa Indonesia. Kunci mengikuti nama pesan
     * bawaan Laravel agar konsisten dengan aturan Password.
     *
     * @var array<string, string>
     */
    public const MESSAGES = [
        'min' => 'Password minimal :min karakter.',
        'password.mixed' => 'Password harus memuat huruf besar dan huruf kecil.',
        'password.numbers' => 'Password harus memuat minimal satu angka.',
    ];

    /**
     * Aturan password yang diterapkan aplikasi.
     */
    public static function rule(): Password
    {
        return Password::min(self::MINIMUM_LENGTH)
            ->mixedCase()
            ->numbers();
    }

    /**
     * True bila password memenuhi kebijakan.
     */
    public static function accepts(?string $password): bool
    {
        if ($password === null || $password === '') {
            return false;
        }

        return validator(['password' => $password], ['password' => self::rule()])->passes();
    }
}
