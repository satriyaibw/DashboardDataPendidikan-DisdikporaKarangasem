<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Pencatat audit aktivitas admin terhadap akun VIP.
 *
 * Prinsip: deny-by-default. Hanya kolom yang ada di AUDITABLE_ATTRIBUTES
 * yang boleh masuk ke log; kolom apa pun di luar daftar dibuang. Dengan
 * begitu PII atau rahasia yang baru ditambahkan ke model kelak tidak
 * pernah ikut tercatat hanya karena lupa memperbarui daftar hitam.
 */
class AdminActivityLogger
{
    /**
     * Kolom user yang boleh tercatat.
     *
     * @var array<int, string>
     */
    public const AUDITABLE_ATTRIBUTES = [
        'name',
        'is_active',
        'expires_at',
        'vip_notes',
        'roles',
    ];

    /**
     * Catat satu aktivitas admin.
     *
     * Kegagalan penulisan audit SENGAJA tidak mengganggu aksi admin: lebih
     * baik jejak hilang daripada panel admin gagal menyimpan perubahan hanya
     * karena tabel audit bermasalah.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function log(string $action, Model $subject, array $properties = []): void
    {
        try {
            static::record($action, $subject, $properties);
        } catch (\Throwable $e) {
            Log::warning('Gagal menulis audit log.', [
                'action' => $action,
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected static function record(string $action, Model $subject, array $properties): void
    {
        $filtered = static::filterProperties($properties);

        $subject->adminActivityLogs()->create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'description' => null,
            'properties' => $filtered === [] ? null : $filtered,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 512),
        ]);
    }

    /**
     * Buang semua key di luar whitelist, semua nilai non-skalar, dan setiap
     * pasangan before/after yang keduanya null, supaya log tidak menjadi
     * 90% kebisingan null.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, array{before: scalar|null, after: scalar|null}>
     */
    public static function filterProperties(array $properties): array
    {
        $filtered = [];

        foreach (self::AUDITABLE_ATTRIBUTES as $attribute) {
            if (! array_key_exists($attribute, $properties)) {
                continue;
            }

            $scalarized = static::scalarize($properties[$attribute]);

            if ($scalarized['before'] === null && $scalarized['after'] === null) {
                continue;
            }

            $filtered[$attribute] = $scalarized;
        }

        return $filtered;
    }

    /**
     * Ubah nilai menjadi pasangan before/after. Nilai array (mis. perubahan
     * yang sudah dibungkus) diurai; nilai polos diperlakukan sebagai
     * "after" saja.
     *
     * @return array{before: scalar|null, after: scalar|null}
     */
    protected static function scalarize(mixed $value): array
    {
        if (! is_array($value)) {
            return ['before' => null, 'after' => static::scalar($value)];
        }

        return [
            'before' => isset($value['before']) ? static::scalar($value['before']) : null,
            'after' => isset($value['after']) ? static::scalar($value['after']) : null,
        ];
    }

    /**
     * Bulatkan nilai menjadi skalar yang aman disimpan pada kolom json.
     *
     * Nilai tanggal/waktu disimpan sebagai ISO 8601 **dengan offset zona
     * waktu** (mis. `2026-11-09T12:00:00+08:00`). Bentuk `Y-m-d H:i:s` yang
     * dihasilkan `__toString()` Carbon tidak dipakai karena tidak menyebut
     * zona waktu: pembaca audit tidak bisa memastikan itu UTC atau waktu
     * server, sehingga "masa berlaku diubah pukul berapa" tidak dapat
     * dibuktikan. Format eksplisit juga tidak ambigu saat dibaca mesin.
     */
    protected static function scalar(mixed $value): string|bool|null
    {
        if (is_bool($value)) {
            return $value;
        }

        // Instans date/time (Carbon hasil cast `datetime`) selalu diformalkan
        // lebih dulu agar sebelum/sesudah memakai format yang sama.
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        // Nilai turunan \Stringable (mis. koleksi peran yang sudah di-join
        // di pemanggil) tetap aman; yang lain dibuang.
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }
}
