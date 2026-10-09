<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['actor_id', 'action', 'subject_type', 'subject_id', 'description', 'properties', 'ip', 'user_agent'])]
class AdminActivityLog extends Model
{
    /**
     * Hanya kolom aman yang boleh diisi massal. Nilai sensitif (password,
     * token, recovery code) sengaja tidak pernah menjadi fillable.
     */
    protected $guarded = [];

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
