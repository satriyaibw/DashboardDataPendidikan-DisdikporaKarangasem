<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AdminActivityLogger;
use Illuminate\Support\Facades\Auth;

class UserObserver
{
    /**
     * Akun VIP dibuat (mis. admin menambah user di panel).
     */
    public function created(User $user): void
    {
        if (Auth::id() === null) {
            return;
        }

        AdminActivityLogger::log('user.created', $user, static::createdProperties($user));
    }

    /**
     * Akun VIP diubah. Perubahan yang tercatat adalah before -> after agar
     * "Perpanjang 30 hari" dan "Aktif/Nonaktifkan" dapat dibaca siapa pun
     * yang meninjau jejak audit.
     */
    public function updated(User $user): void
    {
        if (Auth::id() === null) {
            return;
        }

        AdminActivityLogger::log('user.updated', $user, static::changedProperties($user));
    }

    /**
     * Akun VIP dihapus.
     */
    public function deleted(User $user): void
    {
        if (Auth::id() === null) {
            return;
        }

        AdminActivityLogger::log('user.deleted', $user, static::createdProperties($user));
    }

    /**
     * Kelompokkan setiap atribut menjadi pasangan before/after berdasarkan
     * nilai semula pada model sebelum penyimpanan.
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    protected static function changedProperties(User $user): array
    {
        $changes = [];

        foreach (array_keys($user->getChanges()) as $attribute) {
            if (! in_array($attribute, AdminActivityLogger::AUDITABLE_ATTRIBUTES, true)) {
                continue;
            }

            $changes[$attribute] = [
                'before' => $user->getOriginal($attribute),
                'after' => $user->getAttribute($attribute),
            ];
        }

        return $changes;
    }

    /**
     * Kondisi akhir atribut yang boleh dicatat pada saat acara terjadi.
     *
     * `roles` adalah relasi, bukan kolom: nilainya diambil sebagai daftar nama
     * peran agar tahu akun dibuat sebagai vip/admin.
     *
     * @return array<string, mixed>
     */
    protected static function createdProperties(User $user): array
    {
        $properties = [];

        foreach (AdminActivityLogger::AUDITABLE_ATTRIBUTES as $attribute) {
            if ($attribute === 'roles') {
                continue;
            }

            $properties[$attribute] = $user->getAttribute($attribute);
        }

        $properties['roles'] = $user->roles->pluck('name')->sort()->implode(', ');

        return $properties;
    }
}
