<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['actor_id', 'action', 'subject_type', 'subject_id', 'description', 'properties', 'ip', 'user_agent'])]
class AdminActivityLog extends Model
{
    /**
     * Kolom yang boleh diisi massal didefinisikan oleh atribut `#[Fillable]`
     * di atas. Sengaja tidak ada `$guarded = []` di sini: kombinasi
     * `$guarded = []` dengan daftar fillable terlihat seperti dua kali
     * menyatakan semua kolom fillable, dan kalau atribut Fillable suatu saat diabaikan
     * (mis. saat model di-refactor ke gaya property biasa) seluruh kolom —
     * termasuk `properties` dan `actor_id` — ikut terbuka tanpa disadari.
     *
     * Nilai sensitif (password, token, recovery code) juga tidak pernah
     * menjadi fillable.
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
